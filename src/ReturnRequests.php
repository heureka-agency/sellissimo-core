<?php

namespace Sellissimo\Core;

use Illuminate\Validation\ValidationException;

class ReturnRequests
{
    public function __construct(private Store $store, private Fulfillment $fulfillment, private Catalog $catalog) {}

    public function forOrder(string $id)
    {
        return $this->store->table('return_requests')->where('order_id', $id)->orderBy('created_at')->orderBy('id')->get();
    }

    public function choices(object $order, int $replacementQuantity = 1): array
    {
        $parcels = $this->fulfillment->parcels($order->id);
        $lines = $this->fulfillment->quantities($order, $parcels);
        $policies = app(ReturnPolicy::class)->forOrder($order, $parcels);
        $products = $this->catalog->products($order->site, false);
        $result = [];
        foreach (json_decode($order->receipt, true)['lines'] as $line) {
            $key = $line['key'] ?? $line['sku'];
            $qty = $lines[$key]['returnable'] ?? 0;
            if ($policy = $policies[$key] ?? null) {
                if (in_array($policy['state'], ['final_sale', 'awaiting_delivery', 'window_closed'], true)) {
                    continue;
                }
                $shipped = $line['qty'] - $lines[$key]['remaining'];
                $qty -= max(0, $shipped - $policy['delivered']);
            }
            if ($qty < 1) {
                continue;
            }
            $replacement = $this->replacements($order, $line, $products, $replacementQuantity);
            $result[$key] = ['title' => trim($line['title'].' '.implode(' / ', array_column($line['options'] ?? [], 'value'))), 'quantity' => $qty, 'replacements' => $replacement];
        }

        return $result;
    }

    public function replacements(object $order, array $line, array $products, int $quantity): array
    {
        return collect($products)->filter(fn ($product) => !$product['digital'] && $product['currency'] === $order->currency
            && ($product['sku'] === $line['sku'] || !collect($product['option_fields'])->contains(fn ($field) => $field['required'] ?? false))
            && ($product['available'] === null || $product['available'] >= $quantity))
            ->map(fn ($product) => ['sku' => $product['sku'], 'title' => $product['title'], 'price' => Money::display($product['price'], $order->currency)])->values()->all();
    }

    public function submit(string $id, array $input): void
    {
        $this->store->db()->transaction(function () use ($id, $input) {
            $order = $this->store->table('orders')->where('id', $id)->lockForUpdate()->firstOrFail();
            $match = ['order_id' => $id, 'line_key' => $input['line_key'], 'quantity' => (int) $input['quantity'], 'reason' => $input['reason'], 'replacement' => $input['replacement'] ?? null];
            if ($existing = $this->store->table('return_requests')->where('id', $input['request_id'])->first()) {
                foreach ($match as $key => $value) {
                    abort_unless($value == ($key === 'replacement' ? $existing->requested_replacement : $existing->$key), 409);
                }

                return;
            }
            $line = $this->choices($order, $match['quantity'])[$input['line_key']] ?? null;
            if (! $line || $line['quantity'] < $match['quantity']) {
                throw ValidationException::withMessages(['quantity' => 'The requested quantity exceeds the delivered units available to return.']);
            }
            if ($match['replacement'] && ! in_array($match['replacement'], array_column($line['replacements'], 'sku'), true)) {
                throw ValidationException::withMessages(['replacement' => 'Choose an available replacement for the requested quantity.']);
            }
            $this->store->table('return_requests')->insert(['id' => $input['request_id'], ...$match, 'requested_replacement' => $match['replacement'], 'status' => 'requested', 'created_at' => now(), 'updated_at' => now()]);
            $this->store->recordActivity($id, 'return', 'Return requested for '.$match['quantity'].' × '.$line['title'].'.');
        }, 3);
    }

    public function transition(string $id, array $input, $user): void
    {
        if (in_array($input['action'], ['approve', 'substitute', 'dispatch'], true)) {
            app(StockHolds::class)->expireDue();
        }
        $orderId = $this->store->table('return_requests')->where('id', $id)->firstOrFail(['order_id'])->order_id;
        $this->store->db()->transaction(function () use ($id, $input, $user, $orderId) {
            $order = $this->store->table('orders')->where('id', $orderId)->lockForUpdate()->firstOrFail();
            $query = $this->store->table('return_requests')->where('id', $id);
            $row = $query->lockForUpdate()->firstOrFail();
            $action = $input['action'];
            if ($action === 'shipping') {
                $input['shipping_digest'] = hash('sha256', json_encode([$input['inbound_label_url'] ?? null, $input['inbound_tracking_url'] ?? null,
                    $input['inbound_tracking'] ?? null, isset($input['inbound_label']) ? hash_file('sha256', $input['inbound_label']->getRealPath()) : null], JSON_THROW_ON_ERROR));
            }
            if ($action === 'receive' && $row->status === 'received') {
                $received = $this->store->table('returns')->where('id', $id)->firstOrFail();
                abort_unless((int) $received->restocked === (int) $input['restocked']
                    && (!isset($input['location']) || !$received->restocked || ($received->location ?? 'main') === $input['location']), 409);
            }
            $parcels = app(ExchangeParcels::class);
            if ($action === 'dispatch') {
                if ($parcels->alreadyDispatched($row, $input)) {
                    return;
                }
                $input['operation_id'] ??= (string) \Illuminate\Support\Str::uuid();
            }
            if ($operation = $input['operation_id'] ?? null) {
                $notice = $this->store->table('return_notices')->where('id', $operation)->first();
                if ($notice) {
                    abort_unless($notice->return_id === $id && $notice->action === $action
                        && $notice->message === ($input['message'] ?? null) && $notice->tracking === ($input['tracking'] ?? null)
                        && ($action !== 'shipping' || $notice->shipping_digest === $input['shipping_digest'])
                        && ($action !== 'substitute' || $notice->replacement === $input['replacement']), 409);
                    return;
                }
            }
            $next = ['approve' => 'approved', 'decline' => 'declined', 'receive' => 'received', 'dispatch' => 'exchanged'][$action] ?? null;
            if ($next && $row->status === $next) {
                abort_if($action === 'dispatch', 409);
                return;
            }
            $states = [
                'approve' => ['requested'], 'decline' => ['requested', 'approved'], 'receive' => ['approved'], 'dispatch' => ['received'],
                'substitute' => ['requested', 'approved', 'received'], 'convert' => ['requested', 'approved', 'received'],
                'message' => ['requested', 'approved', 'declined', 'received', 'exchanged'],
                'shipping' => ['requested', 'approved', 'received'],
            ];
            abort_unless(in_array($row->status, $states[$action], true), 409, 'This request has already changed. Refresh the page.');
            $replacement = $row->replacement;
            $line = LineOptions::find(json_decode($order->receipt, true)['lines'], $row->line_key);
            $changes = ['status' => $next ?? $row->status, 'updated_at' => now()];
            $holds = app(StockHolds::class);
            $held = json_decode($row->reserved_stock ?? '[]', true);
            if (in_array($action, ['convert', 'substitute', 'dispatch'], true)) {
                abort_unless($replacement, 422, 'This request has no replacement item.');
            }
            if (in_array($action, ['substitute', 'convert', 'decline'], true)) {
                abort_if($this->store->table('exchange_parcels')->where('return_id', $id)->exists(), 409, __('Some replacement units have already shipped.'));
            }
            if (in_array($action, ['substitute', 'convert', 'decline'], true) && $row->exchange_amendment) {
                abort_unless(app(Amendments::class)->row($row->exchange_amendment)->status === 'expired', 409, __('sellissimo::shop.exchange_committed'));
                $changes['exchange_amendment'] = null;
            }
            if ($action === 'shipping') {
                $changes += ['inbound_tracking' => $input['inbound_tracking'] ?? null, 'inbound_label_url' => $input['inbound_label_url'] ?? null,
                    'inbound_tracking_url' => $input['inbound_tracking_url'] ?? null];
                if ($file = $input['inbound_label'] ?? null) {
                    $disk = 'sellissimo_return_labels';
                    $path = $file->storeAs($id, \Illuminate\Support\Str::uuid().'.pdf', ['disk' => $disk, 'visibility' => 'private']);
                    abort_unless($path, 503);
                    $this->store->db()->afterRollBack(fn () => \Illuminate\Support\Facades\Storage::disk($disk)->delete($path));
                    $changes['inbound_label_url'] = null;
                    $changes['inbound_label_disk'] = $disk;
                    $changes['inbound_label_path'] = $path;
                } elseif ($changes['inbound_label_url']) {
                    $changes['inbound_label_path'] = $changes['inbound_label_disk'] = null;
                }
                if ($row->inbound_label_path && array_key_exists('inbound_label_path', $changes)) {
                    $this->store->db()->afterCommit(fn () => \Illuminate\Support\Facades\Storage::disk($row->inbound_label_disk)->delete($row->inbound_label_path));
                }
            }
            if ($action === 'substitute') {
                $replacement = $input['replacement'];
                abort_if($replacement === $row->replacement, 422, 'Choose a different replacement.');
                $changes['replacement'] = $replacement;
            }
            if ($action === 'convert') {
                $changes['replacement'] = $replacement = null;
                $changes['exchange_quote'] = null;
            }
            // Rows approved before stock holds were introduced still reserve at dispatch.
            if ($replacement && ($action === 'approve' || $action === 'substitute' || ($action === 'dispatch' && $row->reserved_stock === null))) {
                $products = $this->catalog->products($order->site, false, [$replacement]);
                $choices = $line ? $this->replacements($order, $line, $products, $row->quantity) : [];
                if (!$choices) {
                    throw ValidationException::withMessages(['replacement' => 'Choose an available replacement for all requested units.']);
                }
                if ($action !== 'substitute' || $row->status !== 'requested') {
                    $wanted = Inventory::tracked($products[$replacement]) ? [$replacement => (int) $row->quantity] : [];
                    $held = $holds->adjust($held, $wanted, 'Exchange stock reservation', $user->email(), $order->id);
                    $changes['reserved_stock'] = json_encode($held, JSON_THROW_ON_ERROR);
                }
            }
            if ($action === 'convert' || $action === 'decline') {
                $holds->adjust($held, [], 'Exchange reservation released', $user->email(), $order->id);
                $changes['reserved_stock'] = null;
            }
            if (($action === 'receive' && !$replacement) || ($action === 'convert' && $row->status === 'received')) {
                $changes['refund_line_total'] = $line['total'] ?? 0;
                $changes['refund_entitlement'] = app(Refunds::class)->returnEntitlement($order, $row);
            }
            if ($replacement && in_array($action, ['approve', 'substitute'], true)) {
                $changes['exchange_quote'] = json_encode(app(ExchangeBalances::class)->quote($order, $row, $replacement, $line), JSON_THROW_ON_ERROR);
            }
            if ($replacement && $action === 'receive') {
                $changes['exchange_quote'] = json_encode(app(ExchangeBalances::class)->atReceipt($order, $row, $line), JSON_THROW_ON_ERROR);
            }
            if ($action === 'receive') {
                $this->fulfillment->receive($order->id, ['request_id' => $id, 'line_key' => $row->line_key, 'quantity' => $row->quantity, 'restocked' => $input['restocked'], 'location' => $input['location'] ?? null], $user, $id);
            }
            if ($action === 'dispatch') {
                abort_unless(app(ExchangeBalances::class)->settled($row, $order), 409, __('sellissimo::shop.exchange_unsettled'));
                $changes = array_merge($changes, $parcels->dispatch($order, $row, $input, $held));
            }
            if (filled($input['message'] ?? null)) {
                $changes['staff_message'] = $input['message'];
            }
            $query->update($changes);
            $description = ['convert' => 'changed to refund review', 'substitute' => 'substitution selected', 'message' => 'message added', 'shipping' => 'return shipping details updated', 'dispatch' => 'replacement parcel dispatched'][$action] ?? $next;
            $this->store->recordActivity($order->id, 'return', 'Return request '.$description.'.', $user->email(), (string) $user->id());
            app(ReturnNotices::class)->queue($id, $input, $replacement);
        }, 3);
    }
}
