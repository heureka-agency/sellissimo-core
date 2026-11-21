<?php

namespace Sellissimo\Core\Http;

use Sellissimo\Core\StaffSites;
use Illuminate\Http\Request;
use Sellissimo\Core\Checkout;
use Sellissimo\Core\Money;
use Sellissimo\Core\Store;

class OrderDocumentsController
{
    public function export(Request $request, OrderListing $listing, Store $store)
    {
        $orders = $listing->forStaff($request->validate(OrderListing::FILTERS))
            ->select('sellissimo_orders.*')
            ->selectSub($store->table('refunds')->selectRaw('coalesce(sum(amount), 0)')
                ->whereColumn('order_id', 'sellissimo_orders.id')->where('status', 'succeeded'), 'refunded')
            ->selectSub($store->table('amendments')->selectRaw('coalesce(sum(difference), 0)')
                ->whereColumn('order_id', 'sellissimo_orders.id')->where('status', 'applied')->where('difference', '>', 0), 'supplements');

        $columns = array_intersect_key(OrderListing::columnOptions(), array_flip(array_filter(explode(',', (string) $request->query('columns')), fn ($key) => str_starts_with($key, 'field_'))));
        if (($request->validate(['format' => 'nullable|in:summary,lines'])['format'] ?? null) === 'lines') {
            return app(OrderLineExport::class)->download($orders, $columns);
        }
        return response()->streamDownload(function () use ($orders, $columns) {
            $output = fopen('php://output', 'wb');
            $headings = ['Order ID', 'Order number', 'Placed at', 'Site', 'Customer name', 'Email', 'Payment status', 'Fulfillment', 'Tracking', 'Currency', 'Order total', 'Refunded', 'Current order total'];
            fputcsv($output, [...$headings, ...array_values($columns)], ',', '"', '', "\r\n");
            foreach ($orders->lazyById(250) as $order) {
                $receipt = json_decode($order->receipt, true, flags: JSON_THROW_ON_ERROR);
                $summary = OrderView::summary($order, $receipt);
                $fields = [
                    $order->id, $summary['number'], $order->created_at, $order->site, $summary['name'], $order->email,
                    $summary['paymentState'], $summary['fulfillment'], $order->tracking, $order->currency,
                    Money::decimal($order->total - $order->supplements, $order->currency), Money::decimal($order->refunded, $order->currency),
                    Money::decimal($receipt['total'] ?? $order->total, $order->currency),
                ];
                $custom = app(\Sellissimo\Core\OrderQueueFields::class)->values($order, $receipt);
                foreach (array_keys($columns) as $key) {
                    $fields[] = $custom[substr($key, 6)] ?? '';
                }
                fputcsv($output, array_map(self::spreadsheetText(...), $fields), ',', '"', '', "\r\n");
            }
            fclose($output);
        }, 'sellissimo-orders-'.now()->toDateString().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public static function spreadsheetText(?string $value): string
    {
        $value ??= '';

        // Quoting a CSV field alone does not keep a spreadsheet from evaluating it.
        return preg_match('/^(?:[\t\r\n]|[\s\p{Z}\p{Cf}]*[=+@\-＝＋＠－])/u', $value) ? "'".$value : $value;
    }

    public function packingSlip(string $order, Checkout $checkout, \Illuminate\Http\Request $request, Store $store)
    {
        $options = $request->validate(['parcel' => 'sometimes|uuid', 'remaining' => 'sometimes|boolean']);
        $parcel = $options['parcel'] ?? null;
        return view('sellissimo::packing-slip', ['slips' => [$this->document($checkout->order($order), $store, $parcel, $request->boolean('remaining'))]]);
    }

    public function packingSlips(Request $request, Store $store)
    {
        $ids = $request->validate(['orders' => 'required|array|min:1|max:100', 'orders.*' => 'required|uuid|distinct'])['orders'];
        $orders = $store->table('orders')->whereIn('site', StaffSites::handles())->whereIn('id', $ids)->get()->keyBy('id');
        abort_unless($orders->count() === count($ids), 404);
        return view('sellissimo::packing-slip', ['slips' => array_map(fn ($id) => $this->document($orders[$id], $store, remaining: true), $ids)]);
    }

    public function pickList(Request $request, Store $store, \Sellissimo\Core\Fulfillment $fulfillment, \Sellissimo\Core\ParcelLocations $locations)
    {
        $ids = $request->validate(['orders' => 'required|array|min:1|max:100', 'orders.*' => 'required|uuid|distinct'])['orders'];
        $orders = app(OrderListing::class)->forStaff(['view' => 'awaiting'])->whereIn('id', $ids)->get();
        abort_unless($orders->count() === count($ids), 409, __('The shipping queue changed. Select the outstanding orders again.'));
        $groups = [];
        foreach ($orders as $order) {
            $parcels = $fulfillment->parcels($order->id);
            $available = $locations->available($order, $parcels);
            $remaining = $fulfillment->quantities($order, $parcels);
            foreach (json_decode($order->receipt, true)['lines'] as $line) {
                $key = $line['key'] ?? $line['sku'];
                $qty = $remaining[$key]['remaining'] ?? 0;
                if (!$qty || $line['digital']) {
                    continue;
                }
                $sku = $line['sku'];
                $allocations = $available[$sku] ?? ['' => $qty];
                foreach ($allocations as $location => $units) {
                    $pick = min($units, $qty);
                    if (!$pick) {
                        continue;
                    }
                    $groups[$location][$sku] ??= ['title' => $line['title'], 'quantity' => 0, 'orders' => []];
                    $groups[$location][$sku]['quantity'] += $pick;
                    $ref = \Sellissimo\Core\OrderNumber::display($order);
                    $groups[$location][$sku]['orders'][$ref] = ($groups[$location][$sku]['orders'][$ref] ?? 0) + $pick;
                    if (isset($available[$sku][$location])) {
                        $available[$sku][$location] -= $pick;
                    }
                    $qty -= $pick;
                }
            }
        }
        ksort($groups);
        foreach ($groups as &$items) {
            ksort($items);
        }
        unset($items);
        return view('sellissimo::pick-list', ['groups' => $groups, 'locations' => $store->table('locations')->pluck('name', 'code')]);
    }

    private function document(object $row, Store $store, ?string $parcelId = null, bool $remaining = false): array
    {
        $order = $row->id;
        $receipt = json_decode($row->receipt, true, flags: JSON_THROW_ON_ERROR);
        $summary = OrderView::summary($row, $receipt);
        abort_unless($summary['canPrintPackingSlip'], 404);

        $origin = null;
        $lines = collect($receipt['lines'])->reject(fn ($line) => $line['digital']);
        if ($remaining && ! $parcelId) {
            $fulfillment = app(\Sellissimo\Core\Fulfillment::class);
            $quantities = $summary['canPack'] ? $fulfillment->quantities($row, $fulfillment->parcels($order)) : [];
            $lines = $lines->map(fn ($line) => [...$line, 'ordered' => $line['qty'],
                'qty' => $quantities[$line['key'] ?? $line['sku']]['remaining'] ?? 0])->where('qty', '>', 0);
        }
        if ($id = $parcelId) {
            $parcel = $store->table('parcels')->where('order_id', $order)->where('id', $id)->firstOrFail();
            $source = $parcel->origin ?? $store->table('labels')->where('id', $parcelId)->value('origin');
            $origin = $source ? $store->table('locations')->where('code', $source)->value('name') : null;
            $items = json_decode($parcel->items, true, flags: JSON_THROW_ON_ERROR);
            $lines = $lines->filter(fn ($line) => isset($items[$line['key'] ?? $line['sku']]))
                ->map(fn ($line) => [...$line, 'qty' => $items[$line['key'] ?? $line['sku']]]);
        }

        $destination = \Sellissimo\Core\DeliveryAddress::current($row, $receipt);

        return [
            'number' => $summary['number'], 'date' => $summary['date'], 'address' => $destination,
            'instructions' => $receipt['shipping_instructions'] ?? null, 'countryLabel' => StoreCountries::name($destination['country'] ?? null),
            'origin' => $origin, 'method' => $receipt['shipping']['label'] ?? null, 'tracking' => $parcel->tracking ?? ($remaining ? null : $row->tracking),
            'orderFields' => \Sellissimo\Core\CommerceFields::visible($receipt['order_fields'] ?? []), 'lines' => $lines,
        ];
    }
}
