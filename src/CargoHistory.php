<?php

namespace Sellissimo\Core;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Statamic\Dictionaries\Countries;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

class CargoHistory
{
    public function __construct(private Store $store, private Catalog $catalog) {}

    public function order(array $data, string $site, string $filename, array $map): string
    {
        $siteCurrency = $this->catalog->currency($site);
        $currency = $data['currency'] ?? $siteCurrency;
        throw_unless(is_string($currency) && preg_match('/^[A-Z]{3}$/D', $currency), 'Invalid historical currency.');
        $id = $data['id'] ?? null;
        throw_unless(Str::isUuid($id) && preg_match('/^(\d{4}-\d{2}-\d{2})(?:-(\d{4}|\d{6}))?\.(\d{1,16})$/D', $filename, $parts), 'Expected a Cargo order ID and a dated order-number filename.');
        $number = (int) $parts[3];
        $created = Carbon::createFromFormat('!Y-m-d His', $parts[1].' '.str_pad($parts[2], 6, '0'));
        $customer = $data['customer'] ?? null;
        $account = is_string($customer) ? User::find($map['customers'][$customer] ?? $customer) : null;
        throw_if(is_string($customer) && ! $account, 'Map the original customer ID to an existing Statamic account.');
        $email = $account?->email() ?: ($customer['email'] ?? $data['email'] ?? null);
        throw_unless(filter_var($email, FILTER_VALIDATE_EMAIL), 'An account or guest email is required.');
        $status = match ($data['status'] ?? '') {
            'payment_received', 'shipped', 'delivered', 'returned' => 'paid', 'payment_pending' => 'pending', 'cancelled' => 'expired',
            default => throw new \UnexpectedValueException('Unknown Cargo order status.'),
        };
        $lines = [];
        foreach ($data['line_items'] ?? [] as $item) {
            $line = $this->line($item, $site, $map);
            $lines[] = $line;
            if ($count = $this->money($item['download_count'] ?? 0)) {
                $this->store->table('download_usage')->insert(['order_id' => $id, 'line_key' => $line['key'], 'downloads' => $count]);
            }
        }
        throw_unless($lines && count($lines) === count(array_unique(array_column($lines, 'key'))), 'Order lines must have unique references.');
        $total = $this->money($data['grand_total'] ?? null);
        $refunded = $this->money($data['amount_refunded'] ?? 0);
        throw_if($refunded > $total, 'Historical refunds exceed the order total.');
        $paid = $status === 'paid';
        if ($total > 0 && $refunded === $total) {
            $status = 'refunded';
        }
        $shipping = $total - array_sum(array_column($lines, 'total'));
        throw_if($shipping < 0, 'The historical line totals exceed the order total.');
        $physical = in_array(false, array_column($lines, 'digital'), true);
        $sent = in_array($data['status'], ['shipped', 'delivered', 'returned'], true);
        $destination = $this->address($data['shipping_address'] ?? []);
        $receipt = ['currency' => $currency, 'address' => $this->address($data['billing_address'] ?? []) ?: $destination,
            'shipping_address' => $destination, 'lines' => $lines, 'subtotal' => array_sum(array_column($lines, 'subtotal')),
            'discount' => $this->money($data['discount_total'] ?? 0), 'tax' => $this->money($data['tax_total'] ?? 0), 'total' => $total,
            'code' => $data['discount_code'] ?? '', 'shipping_method' => $data['shipping_method'] ?? null,
            'shipping' => $physical || $shipping ? ['amount' => $shipping, 'label' => $data['shipping_method'] ?? 'Delivery', 'tax' => $this->money($data['shipping_tax_total'] ?? 0)] : null,
            'historical_status' => $data['status']];
        $sequence = $this->store->table('order_sequences')->where('name', 'orders');
        $last = (int) (clone $sequence)->lockForUpdate()->value('last_number');
        if ($number > $last) {
            $sequence->update(['last_number' => $number]);
        }
        throw_if($this->store->table('orders')->where('id', $id)->orWhere('order_number', $number)->exists(), 'An order already uses this ID or order number.');
        $this->store->table('orders')->insert(['id' => $id, 'order_number' => $number, 'site' => $site, 'cart_id' => (string) Str::uuid(),
            'currency' => $currency, 'gateway' => 'historical', 'status' => $status, 'customer' => $account?->id(), 'email' => $email,
            'receipt' => json_encode($receipt, JSON_THROW_ON_ERROR), 'tracking' => $sent ? ($data['tracking_number'] ?? '') : null,
            'total' => $total, 'created_at' => $created, 'paid_at' => $paid || $refunded ? $created : null, 'emailed_at' => $created]);
        app(OrderQueueFields::class)->refresh($id);
        Search\StoreRecords::changed("order/$id");
        $this->importRefunds($id, $refunded, $data['timeline_events'] ?? []);
        if ($data['status'] === 'returned') {
            foreach ($lines as $line) {
                if ($line['downloadable']) {
                    $this->store->table('download_blocks')->insert(['order_id' => $id, 'sku' => $line['key']]);
                }
            }
        }
        if ($sent && $physical) {
            $items = collect($lines)->reject(fn ($line) => $line['digital'])->pluck('qty', 'key')->all();
            $this->store->table('parcels')->insert(['id' => (string) Str::uuid(), 'order_id' => $id, 'number' => 1,
                'tracking' => $data['tracking_number'] ?? '', 'items' => json_encode($items, JSON_THROW_ON_ERROR), 'created_at' => $created]);
        }
        $this->store->recordActivity($id, 'import', 'Imported Cargo order with status '.str_replace('_', ' ', $data['status']).'.');

        return $id;
    }

    private function importRefunds(string $order, int $refunded, array $events): void
    {
        $events = collect($events)->where('type', 'order_refunded');
        $recorded = 0;
        foreach ($events as $event) {
            $amount = $this->money($event['metadata']['Amount'] ?? null);
            $date = Carbon::createFromFormat('!Y-m-d H:i:s', $event['datetime']);
            throw_unless($date && $amount > 0, 'Check the date and amount of the historical refund.');
            $recorded += $amount;
            $this->store->table('refunds')->insert(['id' => (string) Str::uuid(), 'order_id' => $order, 'sku' => '@all',
                'amount' => $amount, 'status' => 'succeeded', 'settled_at' => $date,
                'reason' => 'Historical Cargo refund', 'reference' => $order]);
        }
        throw_unless($recorded === $refunded, 'Reconcile the Cargo refund total with its dated refund events before importing.');
    }

    private function line(array $item, string $site, array $map): array
    {
        $entryId = $item['product'] ?? '';
        $variant = $item['variant'] ?? '';
        $source = $entryId.($variant !== '' ? '|'.$variant : '');
        $mapping = $map['products'][$source] ?? [];
        $entry = Entry::find($entryId)?->in($site);
        $sku = $mapping['sku'] ?? ($variant ?: $entry?->value('sellissimo_sku'));
        $product = $sku ? ($this->catalog->products($site, false, [strtoupper($sku)], applyDownloadDefaults: false)[strtoupper($sku)] ?? null) : null;
        throw_unless($product || (isset($mapping['sku'], $mapping['title'], $mapping['digital'])), "Map the source product or variant $source to a SKU.");
        $qty = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT);
        throw_unless($qty > 0, 'Invalid historical quantity.');
        $file = $mapping['download'] ?? $product['file'] ?? null;
        $files = Downloads::paths($file);
        throw_unless(count($files) === count((array) $file), 'Check the mapped private download paths.');
        foreach ($files as $path) {
            throw_unless(Storage::disk(config('sellissimo.download_disk'))->exists($path), 'The mapped private download file is missing.');
        }
        $limit = $this->money($mapping['download_limit'] ?? $this->sourceDownloadLimit($item, $entry) ?? $product['download_limit'] ?? 0);
        $tax = $this->money($item['tax_total'] ?? 0);
        $discount = $this->money($item['discount_total'] ?? 0);
        $total = $this->money($item['total'] ?? 0);
        $sku = strtoupper($sku);

        return ['key' => $item['id'] ?? $sku, 'sku' => $sku, 'entry_id' => $product['entry_id'] ?? $entryId,
            'title' => $mapping['title'] ?? $product['title'], 'product_title' => $product['product_title'] ?? $mapping['title'],
            'price' => $this->money($item['unit_price'] ?? 0), 'qty' => $qty, 'digital' => (bool) ($mapping['digital'] ?? $product['digital']),
            'downloadable' => (bool) $files, 'file' => count($files) > 1 ? $files : ($files[0] ?? null), 'download_limit' => $limit, 'subtotal' => $total + $discount - $tax, 'discount' => $discount, 'tax' => $tax, 'total' => $total, 'options' => []];
    }

    private function sourceDownloadLimit(array $item, $entry): mixed
    {
        if (isset($item['download_limit'])) return $item['download_limit'];
        if (! empty($item['variant'])) {
            return collect($entry?->value('product_variants')['options'] ?? [])->firstWhere('key', $item['variant'])['download_limit'] ?? null;
        }
        return $entry?->value('download_limit');
    }

    public function upgradeDownloadHistory(object $record): void
    {
        $source = json_decode($record->source_record, true, flags: JSON_THROW_ON_ERROR);
        $this->store->db()->transaction(function () use ($record, $source) {
            $order = $this->store->table('orders')->where('id', $record->destination)->lockForUpdate()->first();
            if (! $order) return;
            $receipt = json_decode($order->receipt, true, flags: JSON_THROW_ON_ERROR);
            $sourceItems = collect($source['line_items'] ?? []);
            $changed = false;
            foreach ($receipt['lines'] as $index => &$line) {
                if (array_key_exists('download_limit', $line) || empty($line['file'])) continue;
                $item = $sourceItems->firstWhere('id', $line['key']) ?? $sourceItems->get($index);
                if (! $item || (! empty($item['id']) && $item['id'] !== $line['key'])) continue;
                $count = $this->money($item['download_count'] ?? 0);
                if ($count) {
                    $this->store->table('download_usage')->insertOrIgnore(['order_id' => $order->id, 'line_key' => $line['key'], 'downloads' => $count]);
                }
                $entry = Entry::find($item['product'])?->in($order->site);
                $product = $this->catalog->products($order->site, false, [$line['sku']], applyDownloadDefaults: false)[$line['sku']] ?? [];
                $line['download_limit'] = $this->money($this->sourceDownloadLimit($item, $entry) ?? $product['download_limit'] ?? 0);
                $changed = true;
            }
            if ($changed) $this->store->table('orders')->where('id', $order->id)->update(['receipt' => json_encode($receipt, JSON_THROW_ON_ERROR)]);
        }, 3);
    }

    private function address(array $address): array
    {
        $country = $address['country'] ?? null;
        if (strlen($country ?? '') === 3) {
            $country = collect((new Countries)->optionItems())->firstWhere('iso3', $country)['iso2'] ?? $country;
        }

        return array_filter(['name' => $address['name'] ?? null, 'street' => $address['line_1'] ?? null, 'street2' => $address['line_2'] ?? null,
            'city' => $address['city'] ?? null, 'postal_code' => $address['postcode'] ?? null, 'country' => $country, 'region' => $address['state'] ?? null]);
    }

    private function money(mixed $value): int
    {
        $result = filter_var($value, FILTER_VALIDATE_INT);
        throw_unless($result !== false && $result >= 0, 'Expected a nonnegative amount in minor currency units.');

        return $result;
    }

    public function discount(array $data, string $site, string $source, array $map): string
    {
        $currency = $this->catalog->currency($site);
        $automatic = empty($data['discount_code']);
        $code = $automatic ? 'AUTO-'.strtoupper(substr(hash('sha256', $source), 0, 20)) : strtoupper($data['discount_code']);
        $type = match ($data['type'] ?? '') {
            'amount_off' => 'fixed', 'percentage_off' => 'percentage', 'free_shipping' => 'free_shipping',
            default => throw new \UnexpectedValueException('Map this custom discount type before importing.'),
        };
        $amount = match ($type) {
            'fixed' => $this->money($data['amount_off']), 'percentage' => Money::parseDecimal((string) $data['percentage_off'], 2, 'percentage_off'), default => 0,
        };
        throw_if(($type === 'percentage' && $amount > 10000), 'Review this discount’s percentage before importing.');
        $limit = isset($data['maximum_uses']) ? max(0, (int) $data['maximum_uses'] - (int) ($data['redemptions_count'] ?? 0)) : null;
        throw_if($automatic && $limit !== null, 'Automatic offers with historical redemption limits need a custom rule.');
        throw_if(array_key_exists($code, config('sellissimo.discounts', [])) || $this->store->table('discounts')->where('code', $code)->exists(), 'Discount code already exists.');
        $accounts = [];
        foreach ($data['customers'] ?? [] as $old) {
            $account = User::find($map['customers'][$old] ?? $old);
            throw_unless($account, "Map the discount customer $old to a registered account.");
            $accounts[] = (string) $account->id();
        }
        $targets = [];
        foreach ($data['products'] ?? [] as $originalId) {
            $id = $map['discount_products'][$originalId] ?? $originalId;
            $entry = Entry::find($id);
            throw_unless($entry && $entry->locale() === $site && in_array($entry->collectionHandle(), Catalog::collections(), true),
                "Map discount product $originalId to an entry on site $site in discount_products.");
            $targets[] = $id;
        }
        $this->store->table('discounts')->insert(['code' => $code, 'site' => $site, 'combinable' => true, 'currency' => $currency, 'type' => $type, 'amount' => $amount,
            'automatic' => $automatic, 'combinable' => true, 'name' => $data['title'] ?? $code, 'minimum' => $data['minimum_order_value'] ?? 0,
            'enabled' => $limit !== 0 && ($data['enabled'] ?? true), 'usage_limit' => $limit ?: null,
            'target_customers' => json_encode(array_values(array_unique($accounts)), JSON_THROW_ON_ERROR),
            'target_products' => json_encode(array_values(array_unique($targets)), JSON_THROW_ON_ERROR),
            'starts_on' => ! empty($data['start_date']) ? Carbon::parse($data['start_date'])->toDateString() : null,
            'ends_on' => ! empty($data['end_date']) ? Carbon::parse($data['end_date'])->toDateString() : null]);

        Search\StoreRecords::changed("discount/$code");
        return $code;
    }
}
