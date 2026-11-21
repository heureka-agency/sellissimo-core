<?php

namespace Sellissimo\Core;

use Illuminate\Validation\ValidationException;

class DeliveryAddress
{
    public function __construct(private Store $store) {}

    public static function current(object $order, array $receipt): array
    {
        return ! empty($order->delivery_override) ? json_decode($order->delivery_override, true, flags: JSON_THROW_ON_ERROR) : ($receipt['shipping_address'] ?? $receipt['address'] ?? []);
    }

    public function correct(string $id, array $address, string $previous, $user): bool
    {
        return $this->store->db()->transaction(function () use ($id, $address, $previous, $user) {
            $order = $this->store->table('orders')->where('id', $id)->lockForUpdate()->firstOrFail();
            abort_if(app(Amendments::class)->pending($order->id), 409, 'Settle the open order amendment before changing delivery.');
            $receipt = json_decode($order->receipt, true, flags: JSON_THROW_ON_ERROR);
            $lines = array_values(array_filter($receipt['lines'], fn ($line) => ! $line['digital']));
            abort_unless($lines && in_array($order->status, ['paid', 'awaiting_collection'], true) && ! $order->tracking
                && ! $this->store->table('parcels')->where('order_id', $id)->exists(), 409, 'The destination is locked after dispatch.');
            if ($this->store->table('labels')->where('order_id', $id)->where('status', '!=', 'void')->exists()) {
                throw ValidationException::withMessages(['shipping_address' => 'Void the existing label before changing its destination.']);
            }
            $old = self::current($order, $receipt);
            $address = PostalRegions::address(array_filter($address, fn ($value) => filled($value)), 'shipping_address.region');
            if ($old == $address) {
                return false;
            }
            abort_unless(hash_equals(hash('sha256', json_encode($old)), $previous), 409, 'The delivery address changed. Reopen the order.');
            $message = 'Delivery address corrected from '.implode(', ', array_filter($old)).' to '.implode(', ', $address).'.';
            // Shipping extensions can use any address field to determine service, including street or company.
            try {
                $review = $this->pricingChanged($receipt, $lines, $old, $address, $order);
            } catch (\Throwable $e) {
                report($e);
                $review = true;
            }
            if ($review) {
                $message .= ' Review the delivery charge and tax for this destination. The amount collected is unchanged.';
            }
            $this->store->table('orders')->where('id', $id)->update(['delivery_override' => json_encode($address, JSON_THROW_ON_ERROR)]);
            $this->store->recordActivity($id, 'address', $message, $user->name() ?: $user->email(), (string) $user->id());

            return $review;
        }, 3);
    }

    private function pricingChanged(array $receipt, array $lines, array $old, array $address, object $order): bool
    {
        $registry = app(Registry::class);
        [$handle, $service] = array_pad(explode('.', $receipt['shipping_method'] ?? '', 2), 2, '');
        $carrier = $registry->enabled('shipping')[$handle] ?? null;
        if (! $carrier) {
            return true;
        }
        $before = $carrier->quotes($lines, $old, $order->currency)[$service]['amount'] ?? null;
        $after = $carrier->quotes($lines, $address, $order->currency)[$service]['amount'] ?? null;
        if ($after === null || $before !== $after) {
            return true;
        }
        $settings = app(TaxSettings::class)->forSite($order->site);
        if (($settings['physical_address'] ?? 'billing') === 'billing') {
            return false;
        }
        $provider = isset($settings['provider']) ? $registry->registered('tax_providers', $settings['provider']) : null;
        $rates = fn ($location) => $provider ? $provider->rates($location, $order->site) : app(PricingRules::class)->taxRates($location);

        return $rates($old) !== $rates($address);
    }
}
