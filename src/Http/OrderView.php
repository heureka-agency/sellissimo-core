<?php

namespace Sellissimo\Core\Http;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Sellissimo\Core\Money;
use Sellissimo\Core\Refunds;
use Sellissimo\Core\Registry;
use Sellissimo\Core\Store;

class OrderView
{
    public static function summary(object $order, array $receipt): array
    {
        $physical = collect($receipt['lines'])->contains(fn ($line) => ! $line['digital']);
        $placed = Carbon::parse($order->created_at)->locale(app()->getLocale());
        $returned = (int) ($order->returned_units ?? 0);
        $physicalUnits = collect($receipt['lines'])->reject(fn ($line) => $line['digital'])->sum('qty');
        $fulfillment = match (true) {
            ! $physical && collect($receipt['lines'])->contains(fn ($line) => ($line['downloadable'] ?? true) === false) => 'No shipping required',
            ! $physical => 'Digital only',
            $returned >= $physicalUnits && $physicalUnits > 0 => 'Returned',
            $returned > 0 => 'Partially returned',
            (bool) ($order->partially_shipped ?? false) => 'Partially shipped',
            ($receipt['historical_status'] ?? null) === 'returned' => 'Returned',
            ($receipt['historical_status'] ?? null) === 'delivered' => 'Delivered',
            ($order->parcel_count ?? 0) > 0 && (int) $order->delivered_parcels === (int) $order->parcel_count => 'Delivered',
            $order->tracking !== null => 'Shipped',
            in_array($order->status, ['paid', 'awaiting_collection'], true) => 'Awaiting shipment',
            $order->status === 'pending' => 'Awaiting payment',
            default => 'Not shipped',
        };

        return [
            'id' => $order->id, 'number' => \Sellissimo\Core\OrderNumber::display($order),
            'site' => $order->site, 'gateway' => $order->gateway,
            'date' => $placed->isoFormat('ll'), 'time' => $placed->isoFormat('LT'),
            'name' => $receipt['address']['name'] ?? null, 'email' => $order->email,
            'status' => $order->status, 'paymentState' => $order->payment_state ?? $order->status, 'openReturns' => (int) ($order->open_returns ?? 0), 'fulfillment' => $fulfillment, 'currency' => $order->currency,
            'disputeAlert' => (int) ($order->dispute_alerts ?? 0) > 0,
            'canPack' => $physical && in_array($order->status, ['paid', 'awaiting_collection'], true)
                && (!filled($order->tracking) || $order->partially_shipped) && !in_array($fulfillment, ['Delivered', 'Returned'], true),
            'canPrintPackingSlip' => $physical && (in_array($order->status, ['paid', 'awaiting_collection'], true) || filled($order->tracking)),
            'total' => Money::display($receipt['total'] ?? $order->total, $order->currency),
        ];
    }

    public function detail(object $order, Store $store, Refunds $access, Registry $providers): array
    {
        $receipt = json_decode($order->receipt, true, flags: JSON_THROW_ON_ERROR);
        $destination = \Sellissimo\Core\DeliveryAddress::current($order, $receipt);
        if ($order->delivery_override) {
            $receipt['shipping_address'] = $destination;
        }
        $money = fn ($value) => Money::display($value, $order->currency);
        $refunds = $store->table('refunds')->where('order_id', $order->id)->get();
        $reserved = $refunds->whereIn('status', ['pending', 'succeeded']);
        $balance = max(0, $order->total - $reserved->sum('amount'));
        $blocked = $store->table('download_blocks')->where('order_id', $order->id)->pluck('sku');
        $fulfillment = app(\Sellissimo\Core\Fulfillment::class);
        $parcels = $fulfillment->parcels($order->id);
        $quantities = $fulfillment->quantities($order, $parcels);
        $operational = app(\Sellissimo\Core\OperationalFields::class);
        $currentFields = $operational->current($order, $receipt);
        $controls = request()->user()->can('edit sellissimo order fields') ? $operational->controls() : [];
        $fields = fn ($values) => collect($values)->map(fn ($field) => [...$field, 'url' => isset($field['file_id']) ? cp_route('sellissimo.artwork', ['order' => $order->id, 'file' => $field['file_id']]) : null])->values();
        $lines = [];
        $targets = [];
        $lineLabels = [];
        foreach ($receipt['lines'] as $line) {
            $key = $line['key'] ?? $line['sku'];
            $lineLabels[$key] = trim($line['title'].' '.implode(' / ', array_column($line['options'] ?? [], 'value')));
            $remaining = min($balance, $line['total'] - $reserved->where('sku', $key)->sum('amount'));
            $unusedGift = null;
            if (!empty($line['voucher_product'])) {
                $unusedGift = (int) $store->table('vouchers')->where('purchase_id', $order->id)->where('line_key', $key)->sum('balance');
                $face = $line['price'] * $line['qty'];
                $remaining = min($remaining, \Brick\Math\BigInteger::of($unusedGift)->multipliedBy($line['total'])->quotient(max(1, $face))->toInt());
            }
            if ($remaining > 0) {
                $targets[] = ['sku' => $key, 'label' => $lineLabels[$key], 'maximum' => (int) $remaining];
            }
            $downloadable = $line['downloadable'] ?? $line['digital'];
            $downloads = $downloadable ? app(\Sellissimo\Core\Downloads::class)->count($order->id, $line) : null;
            $limit = $line['download_limit'] ?? 0;
            $lines[] = [
                'sku' => $line['sku'], 'key' => $key, 'options' => $line['options'] ?? [], 'name' => $line['title'], 'qty' => $line['qty'], 'digital' => $line['digital'], 'downloadable' => $downloadable,
                ...($quantities[$key] ?? []),
                'fields' => $fields($line['line_fields'] ?? []), 'savings' => collect($receipt['promotions'] ?? [])->filter(fn ($offer) => !empty($offer['allocations'][$key]))->map(fn ($offer) => $offer['label'].' · '.$money($offer['allocations'][$key]))->values(),
                'amount' => $money($line['subtotal'] ?? $line['total']),
                'unusedVoucher' => $unusedGift === null ? null : $money($unusedGift),
                'downloadCount' => $downloads,
                'downloadUsage' => $downloads === null ? null : ($limit
                    ? __(':count of :limit downloads used', ['count' => $downloads, 'limit' => $limit])
                    : ($downloads === 1 ? __('1 download used') : __(':count downloads used', ['count' => $downloads]))),
                'downloadLimit' => $limit, 'blocked' => $blocked->contains($key), 'accessible' => $line['digital'] && $access->downloadable($order, $line),
            ];
        }
        $shipping = $receipt['shipping']['amount'] ?? 0;
        $remainingShipping = min($balance, $shipping - $reserved->where('sku', '@shipping')->sum('amount'));
        if ($remainingShipping > 0) {
            $targets[] = ['sku' => '@shipping', 'label' => 'Shipping', 'maximum' => (int) $remainingShipping];
        }
        foreach ($receipt['charges'] ?? [] as $handle => $charge) {
            $key = '@charge:'.$handle;
            $lineLabels[$key] = $charge['label'];
            $remaining = min($balance, $charge['total'] - $reserved->where('sku', $key)->sum('amount'));
            if ($remaining > 0) {
                $targets[] = ['sku' => $key, 'label' => $charge['label'], 'maximum' => (int) $remaining];
            }
        }
        $supportsRefunds = $order->status === 'paid' && $balance > 0 && $providers->supportsRefunds($order->gateway);
        $settled = $refunds->where('status', 'succeeded')->sum('amount');

        $remainingStock = app(\Sellissimo\Core\ParcelLocations::class)->available($order, $parcels);
        $locationNames = $store->table('locations')->orderBy('name')->pluck('name', 'code');
        $locations = [];
        foreach ($remainingStock as $sku => $at) {
            foreach ($at as $code => $quantity) {
                if ($quantity > 0) {
                    $locations[] = ['sku' => (string) $sku, 'code' => $code, 'location' => $locationNames[$code] ?? $code, 'quantity' => $quantity];
                }
            }
        }

        $carrier = explode('.', $receipt['shipping_method'] ?? '')[0];
        $label = app(\Sellissimo\Core\CarrierLabels::class)->active($order->id);
        if ($label) {
            $label->items = json_decode($label->items, true);
            if (!request()->user()?->can('fulfill sellissimo orders')) {
                unset($label->label_url, $label->reference);
            }
        }
        $changes = $store->table('amendments')->where('order_id',$order->id)->orderByDesc('created_at')->get();
        $amend = request()->user()?->can('amend sellissimo orders');
        $editable = $amend && $order->status === 'paid' && $order->tracking === null && ! $label
            && \Statamic\Facades\Site::all()->has($order->site) && \Sellissimo\Core\StoreSettings::forSection($order->site, 'currency', 'currency', config('sellissimo.currencies.'.$order->site)) === $order->currency
            && $changes->whereIn('status', ['awaiting_payment', 'awaiting_refund'])->isEmpty() && $reserved->whereNull('amendment_id')->isEmpty();
        $amendProducts = $editable ? app(\Sellissimo\Core\Catalog::class)->products($order->site, false, array_column($lines, 'sku')) : [];
        $disputeMovements = $store->table('dispute_movements')->where('order_id', $order->id)->orderBy('occurred_at')->orderBy('reference')
            ->get()->groupBy('dispute_id');
        $amendItems = $editable ? json_decode($store->table('carts')->where('id', $order->cart_id)->value('items') ?? '{}', true) : [];
        return [
            'reconcileLinks' => collect([$order->payment_ref ? $order->gateway : null, ...$changes->where('status', 'applied')->where('difference', '>', 0)->whereNotNull('payment_ref')->pluck('gateway')])
                ->filter(fn ($handle) => $handle && request()->user()?->can('refund sellissimo orders') && $providers->paymentReconciler($handle))
                ->unique()->mapWithKeys(fn ($handle) => [$handle => cp_route('sellissimo.payments.reconcile', ['order' => $order->id, 'gateway' => $handle])]),
            'disputes' => $store->table('disputes')->where('order_id', $order->id)->orderBy('id')->get()->map(fn ($row) => [
                'id' => $row->id, 'amount' => Money::display($row->amount, $row->currency), 'status' => Str::headline($row->status),
                'movements' => $disputeMovements->get($row->id, collect())->map(fn ($movement) => [
                    'date' => $movement->occurred_at, 'reference' => $movement->reference,
                    'amount' => Money::display($movement->amount, $movement->currency),
                    'fee' => $movement->fee ? Money::display($movement->fee, $movement->currency) : null,
                ])->values(),
                'reason' => Str::headline($row->reason ?? ''), 'respondBy' => $row->respond_by ? Carbon::parse($row->respond_by, 'UTC')->locale(app()->getLocale())->isoFormat('lll').' UTC' : null,
            ]),
            'extensionPanels' => $providers->orderPanels($order, $receipt, request()->user()),
            'providerDetails' => [
                'shipping' => $providers->orderDetails('shipping', $carrier, $order, $receipt, request()->user()),
                'payment' => $providers->orderDetails('payments', $order->gateway, $order, $receipt, request()->user()),
            ],
            'coupon' => $receipt['code'] ?? '', 'shippingKey' => $receipt['shipping_method'] ?? null, 'amendVersion' => app(\Sellissimo\Core\Amendments::class)->version($order),
            'amendRequestId' => (string) Str::uuid(), 'amendments' => $changes->map(fn ($row) => ['id'=>$row->id,'status'=>Str::headline($row->status),'reason'=>$row->reason,'balance'=>$money($row->difference),
                'link'=>$amend && $row->status === 'awaiting_payment' ? AmendmentController::link($row) : null,
                'emailUrl'=>$amend && $row->status === 'awaiting_payment' ? cp_route('sellissimo.amend.email',$row->id) : null,
                'retryUrl'=>$amend && ($row->status === 'awaiting_refund' || $refunds->where('amendment_id', $row->id)->contains('status', 'failed')) ? cp_route('sellissimo.amend.retry',$row->id) : null]),
            'canAmend' => $editable,
            'amendCatalog' => collect($amendProducts)->map(fn ($item) => \Illuminate\Support\Arr::only($item, ['title', 'option_fields', 'digital'])),
            'amendFieldValues' => collect($amendItems)->map(fn ($item) => is_array($item) ? $item['line_fields'] ?? [] : []),
            'amendProducts' => $amend ? cp_route('sellissimo.amend.products', $order->id) : null,
            'amendDelivery' => $amend ? cp_route('sellissimo.amend.delivery', $order->id) : null,
            'amendArtwork' => $amend ? cp_route('sellissimo.amend.artwork', $order->id) : null,
            'amendLineFields' => (object) ($editable ? app(\Sellissimo\Core\CommerceFields::class)->controls('line', true) : []),
            'amendPreview' => $amend ? cp_route('sellissimo.amend.preview',$order->id) : null, 'amendUrl' => $amend ? cp_route('sellissimo.amend',$order->id) : null,
            'carrier' => isset($providers->enabled('carriers')[$carrier]) ? $carrier : null,
            'activeLabel' => $label, 'origins' => $locationNames, 'reassignRequestId' => (string) Str::uuid(),
            ...self::summary($order, $receipt),
            'contactUrl' => $order->customer === null && request()->user()->can('correct sellissimo guest contacts') ? cp_route('sellissimo.contact', $order->id) : null,
            'contactRevision' => (int) $order->contact_revision,
            'fieldsUrl' => $controls ? cp_route('sellissimo.fields', $order->id) : null, 'fieldsRevision' => (int) $order->fields_revision,
            'fieldControls' => (object) $controls, 'fieldInputs' => (object) $operational->inputs($currentFields, $controls),
            'fields' => $fields($currentFields)->concat(collect($receipt['checkout_steps'] ?? [])->flatMap(
                fn ($step) => collect($step['fields'])->map(fn ($field) => ['label' => $step['label'].' · '.$field['label'], 'value' => $field['display'], 'url' => null])->values()
            )), 'deliveryVersion' => hash('sha256', json_encode($destination)), 'countries' => StoreCountries::labels(),
            'canCorrectAddress' => !$label && !$order->tracking && $quantities && in_array($order->status, ['paid', 'awaiting_collection'], true),
            'returnPolicies' => app(\Sellissimo\Core\ReturnPolicy::class)->forOrder($order, $parcels), 'stockLocations' => $locations, 'parcels' => $parcels, 'returnsUrl'=>request()->user()?->can('view sellissimo returns') ? cp_route('sellissimo.returns',['order'=>$order->id]) : null, 'parcelRequestId' => (string) Str::uuid(), 'returnRequestId' => (string) Str::uuid(),
            'address' => $receipt['address'] ?? [], 'shippingInstructions' => $receipt['shipping_instructions'] ?? null, 'shippingAddress' => $receipt['shipping_address'] ?? null, 'shippingCountry' => StoreCountries::name($receipt['shipping_address']['country'] ?? null), 'countryLabel' => StoreCountries::name($receipt['address']['country'] ?? null), 'lines' => $lines,
            'subtotal' => $money($receipt['subtotal'] ?? collect($receipt['lines'])->sum('total') + $receipt['discount'] - $receipt['tax']),
            'promotion' => $receipt['promotion'] ?? null,
            'promotions' => collect($receipt['promotions'] ?? array_filter([$receipt['promotion'] ?? null]))->map(fn ($p) => [...$p, 'formatted' => $money($p['discount'])])->values(),
            'discount' => $receipt['discount'] ? $money(-$receipt['discount']) : null,
            'tax' => $receipt['tax'] ? $money($receipt['tax']) : null, 'shipping' => $money($shipping - ($receipt['shipping']['tax'] ?? 0)),
            'charges' => \Sellissimo\Core\OrderCharges::display($receipt),
            'deliveryTax' => isset($receipt['shipping']['tax']) ? $money($receipt['shipping']['tax']) : null,
            'method' => $receipt['shipping']['label'] ?? null, 'tracking' => $order->tracking, 'shipmentEmailQueued' => (bool) $order->delivery_queued_at, 'shipmentEmailSent' => (bool) $order->delivery_emailed_at,
            'voucherTender' => $order->voucher_amount ? $money($order->voucher_amount) : null,
            'originalCash' => $order->voucher_amount ? $money($order->original_cash_amount) : null,
            'gateway' => Str::headline($order->gateway), 'site' => $order->site,
            'canCancel' => in_array($order->status, ['paid', 'refunded'], true) && $order->gateway !== 'historical' && !$order->tracking && !$label && !$parcels->count() && !$refunds->where('status', 'pending')->count() && !app(\Sellissimo\Core\Amendments::class)->pending($order->id),
            'cancellationReason' => $order->cancellation_reason ?? null,
            'canRetryCancellation' => $order->status === 'cancelled' && $balance > 0 && !$refunds->where('status', 'pending')->count(),
            'canRefund' => $supportsRefunds, 'canManualRefund' => $order->status === 'paid' && $balance > 0, 'balance' => $money($balance), 'digits' => Money::fractionDigits($order->currency),
            'targets' => $targets, 'requestId' => (string) Str::uuid(), 'refunded' => $settled ? $money($settled) : null,
            'refunds' => $refunds->map(fn ($row) => ['sku' => $row->sku, 'label' => $lineLabels[$row->sku] ?? null, 'amount' => $money($row->amount), 'status' => $row->status, 'manual' => !$row->voucher_id && (bool) $row->manual, 'toVoucher' => (bool) $row->voucher_id, 'reference' => $row->reference ?? $row->provider_ref])->values(),
            'actions' => collect(['labels', 'address', 'reassign', 'ship', 'delivered', 'receive', 'shipment-email', 'settle-delivery', 'cancel', 'cancel.retry', 'refund', 'resend', 'download-access', 'packing-slip'])
                ->filter(fn ($name) => request()->user()?->can(\Sellissimo\Core\StaffAccess::ability($name)))->mapWithKeys(fn ($name) => [$name => cp_route('sellissimo.'.$name, $order->id)]),
        ];
    }
}
