<?php

namespace Sellissimo\Core;

use Brick\Math\BigInteger;

class PricingRules
{
    public function __construct(private Store $store) {}

    public function promotion(string $code, string $currency, int $subtotal): array
    {
        $offer = $this->offer($code, $currency, $subtotal);

        return [$offer['discount'] ?? 0, $offer['free_shipping'] ?? false];
    }

    public function offer(string $code, string $currency, int $subtotal, bool $physical = true, ?string $email = null, ?string $customer = null, bool $placingOrder = false, array $lines = []): ?array
    {
        $lines = $lines ?: [['key' => 'cart', 'sku' => '', 'entry_id' => '', 'subtotal' => $subtotal, 'digital' => !$physical]];
        return $this->offers($code, $currency, $lines, $email, $customer, $placingOrder)[0] ?? null;
    }

    public function offers(string $code, string $currency, array $lines, ?string $email, ?string $customer, bool $placingOrder, ?string $existingOrder = null, ?string $site = null): array
    {
        $site ??= \Statamic\Facades\Site::current()->handle();
        $today = now()->toDateString();
        $remaining = array_column($lines, 'subtotal', 'key');
        $subtotal = array_sum($remaining);
        $offers = [];
        $physical = in_array(false, array_column($lines, 'digital'), true);
        if ($code !== '') {
            $uses = new CouponRedemptions($this->store);
            $query = $this->store->table('discounts')->where('code', $code);
            if ($placingOrder) {
                $uses->lock($code);
                $query->lockForUpdate();
            }
            if ($rule = $query->first()) {
                abort_unless(!$rule->automatic && $this->eligibleCustomer($rule, $customer) && $rule->enabled && $rule->currency === $currency && (!$rule->site || $rule->site === $site)
                    && (!$rule->starts_on || $rule->starts_on <= $today) && (!$rule->ends_on || $rule->ends_on >= $today), 422, 'This discount is unavailable.');
                abort_if($subtotal < $rule->minimum, 422, __('sellissimo::shop.the_cart_does_not_meet_the_minimum_spend_for_this_discount'));
                $offer = $this->applyRule($rule, $lines, $remaining);
                abort_unless($offer, 422, __('sellissimo::shop.the_cart_contains_no_items_eligible_for_this_discount'));
                $uses->check($code, $rule->usage_limit, $rule->per_customer_limit, $email, $customer, $placingOrder, $existingOrder);
                $offers[] = $offer;
                if (!$rule->combinable) {
                    return $offers;
                }
            } else {
                $legacy = config('sellissimo.discounts')[$code] ?? null;
                abort_unless($legacy, 422, 'Unknown discount code.');
                $amount = isset($legacy['rule'])
                    ? app(Registry::class)->registered('discount_rules', $legacy['rule'])->amount($lines, $currency, $legacy)
                    : (isset($legacy['basis_points']) ? self::roundedRate($subtotal, $legacy['basis_points']) : ($legacy['amounts'][$currency] ?? null));
                abort_unless(is_int($amount) && $amount >= 0, 422, __('sellissimo::shop.discount_unavailable_in_this_currency'));
                $uses->check($code, $legacy['usage_limit'] ?? null, $legacy['per_customer_limit'] ?? null, $email, $customer, $placingOrder, $existingOrder);
                $discount = min($subtotal, $amount);
                return [['id' => $code, 'label' => $code, 'automatic' => false, 'customer_limited' => isset($legacy['per_customer_limit']),
                    'discount' => $discount, 'free_shipping' => false, 'allocations' => $this->allocate($discount, $remaining)]];
            }
        }

        $automatic = $this->store->table('discounts')->where('automatic', true)->where('enabled', true)->where('currency', $currency)
            ->where(fn ($q) => $q->whereNull('site')->orWhere('site', $site))
            ->where('minimum', '<=', $subtotal)->where(fn ($q) => $q->whereNull('starts_on')->orWhere('starts_on', '<=', $today))
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $today))
            ->when(!$physical, fn ($q) => $q->where('type', '!=', 'free_shipping'))
            ->orderBy('priority')->orderBy('name')->orderBy('code')->get();
        foreach ($automatic as $rule) {
            if (!$this->eligibleCustomer($rule, $customer) || ($offers && !$rule->combinable) || ($rule->type === 'free_shipping' && in_array(true, array_column($offers, 'free_shipping'), true))) {
                continue;
            }
            if ($offer = $this->applyRule($rule, $lines, $remaining)) {
                $offers[] = $offer;
                if (!$rule->combinable) {
                    break;
                }
            }
        }
        return $offers;
    }

    private function eligibleCustomer(object $rule, ?string $customer): bool
    {
        $allowed = json_decode($rule->target_customers ?? '[]', true, flags: JSON_THROW_ON_ERROR);
        return ! $allowed || ($customer !== null && in_array($customer, $allowed, true));
    }

    private function applyRule(object $rule, array $lines, array &$remaining): ?array
    {
        $skus = json_decode($rule->target_skus ?? '[]', true);
        $products = json_decode($rule->target_products ?? '[]', true);
        $eligible = [];
        foreach ($lines as $line) {
            if ((!$skus && !$products) || in_array($line['sku'], $skus, true) || in_array($line['entry_id'], $products, true)) {
                $eligible[$line['key']] = $remaining[$line['key']];
            }
        }
        if (!$eligible) {
            return null;
        }
        $basis = array_sum($eligible);
        if ($rule->type === 'extension') {
            $provider = app(Registry::class)->enabled('discount_rules')[$rule->rule_handle] ?? null;
            if (! $provider) {
                return null;
            }
            $targets = [];
            foreach ($lines as $line) {
                if (isset($eligible[$line['key']])) {
                    $targets[] = [...$line, 'subtotal' => $eligible[$line['key']]];
                }
            }
            $custom = min($basis, max(0, $provider->amount($targets, $rule->currency, json_decode($rule->rule_settings ?? '{}', true))));
            if ($custom === 0) {
                return null;
            }
        }
        $amount = match ($rule->type) {
            'extension' => $custom,
            'percentage' => self::roundedRate($basis, $rule->amount),
            'fixed' => min($basis, (int) $rule->amount),
            'free_shipping' => 0,
        };
        $shares = $this->allocate($amount, $eligible);
        foreach ($shares as $key => $share) {
            $remaining[$key] -= $share;
        }
        return ['id' => $rule->code, 'label' => $rule->name ?: $rule->code, 'automatic' => (bool) $rule->automatic,
            'customer_limited' => $rule->per_customer_limit !== null, 'discount' => $amount, 'free_shipping' => $rule->type === 'free_shipping', 'allocations' => $shares];
    }

    private function allocate(int $amount, array $eligible): array
    {
        $basis = array_sum($eligible);
        $shares = [];
        foreach ($eligible as $key => $value) {
            $share = $basis ? BigInteger::of($amount)->multipliedBy($value)->plus(intdiv($basis, 2))->quotient($basis)->toInt() : 0;
            $basis -= $value;
            $amount -= $share;
            if ($share) {
                $shares[$key] = $share;
            }
        }
        return $shares;
    }

    public function billingFields(array $address, array $classes): array
    {
        $zones = $this->store->table('tax_zones')->where('enabled', true)
            ->where('country', $address['country'] ?? '')->whereIn('tax_class', $classes)->get();
        $fields = ['country'];
        if ($zones->contains(fn ($zone) => filled($zone->region))) {
            $fields[] = 'region';
        }
        if ($zones->contains(fn ($zone) => filled($zone->postcode) && (! $zone->region
            || blank($address['region'] ?? null) || PostalRegions::matches($address['country'], $zone->region, $address['region'])))) {
            $fields[] = 'postal_code';
        }

        return $fields;
    }

    public function taxRates(array $address): array
    {
        $rates = config('sellissimo.taxes')[$address['country'] ?? ''] ?? [];
        $matched = [];
        $zones = $this->store->table('tax_zones')->where('enabled', true)->where('country', $address['country'] ?? '')->get();
        foreach ($zones as $zone) {
            if ($zone->region && ! PostalRegions::matches($address['country'], $zone->region, $address['region'] ?? '')) {
                continue;
            }
            if ($zone->postcode && ! PostalRegions::postcode($zone->postcode, $address['postal_code'] ?? '')) {
                continue;
            }
            $matched[$zone->tax_class] = ($matched[$zone->tax_class] ?? 0) + $zone->basis_points;
        }

        return array_replace($rates, $matched);
    }

    public function inclusiveClasses(): array
    {
        return $this->store->table('tax_classes')->where('inclusive', true)->pluck('handle')->all();
    }

    public static function roundedRate(int $amount, int $points, bool $inclusive = false): int
    {
        throw_if($points < 0, 'Invalid percentage.');
        $denominator = 10000 + ($inclusive ? $points : 0);

        return BigInteger::of($amount)->multipliedBy($points)->plus(intdiv($denominator, 2))->quotient($denominator)->toInt();
    }
}
