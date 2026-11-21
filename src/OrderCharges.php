<?php

namespace Sellissimo\Core;

use UnexpectedValueException;

class OrderCharges
{
    public function __construct(private Registry $providers) {}

    public function quote(array $receipt, string $site, ?string $customer, array $rates, array $inclusiveClasses): array
    {
        $handles = $this->providers->configured('order_charge_providers');
        if (! $handles || ! $receipt['lines']) {
            return [];
        }

        $quote = Pricing::publicReceipt($receipt);
        $charges = [];
        foreach (array_unique($handles) as $handle) {
            $provider = $this->providers->registered('order_charge_providers', $handle);
            $charge = $provider->charge($quote, $site, $customer);
            if ($charge === null) {
                continue;
            }
            $amount = $charge['amount'] ?? null;
            $label = $charge['label'] ?? null;
            $class = $charge['tax_class'] ?? null;
            if (! is_int($amount) || $amount < 0 || $amount > 99999999 || ! is_string($label) || trim($label) === ''
                || ($class !== null && (! is_string($class) || trim($class) === ''))) {
                throw new UnexpectedValueException("Invalid order charge from Sellissimo provider: $handle");
            }
            if ($amount === 0) {
                continue;
            }

            $inclusive = in_array($class, $inclusiveClasses, true);
            $tax = $class === null ? 0 : PricingRules::roundedRate($amount, $rates[$class] ?? 0, $inclusive);
            $subtotal = $inclusive ? $amount - $tax : $amount;
            $charges[$handle] = ['label' => trim($label), 'subtotal' => $subtotal, 'tax_class' => $class,
                'tax' => $tax, 'total' => $subtotal + $tax];
        }

        return $charges;
    }

    public static function display(array $receipt): array
    {
        $rows = [];
        foreach ($receipt['charges'] ?? [] as $handle => $charge) {
            $rows[$handle] = ['label' => $charge['label'], 'amount' => Money::display($charge['subtotal'], $receipt['currency'])];
        }
        return $rows;
    }
}
