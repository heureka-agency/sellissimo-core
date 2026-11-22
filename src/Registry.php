<?php

namespace Sellissimo\Core;

use InvalidArgumentException;
use Sellissimo\Core\Contracts\PaymentGateway;
use Sellissimo\Core\Contracts\ShippingProvider;

final class Registry
{
    private array $providers = [];
    private array $beforeReview = [];
    private array $panelPermissions = [];

    public function shipping(string $handle, ShippingProvider $provider): void
    {
        $this->add('shipping', $handle, $provider);
    }

    public function carrier(string $handle, Contracts\Carrier $provider): void
    {
        $this->add('carriers', $handle, $provider);
    }

    public function payment(string $handle, PaymentGateway $provider): void
    {
        $this->add('payments', $handle, $provider);
    }

    public function tax(string $handle, Contracts\TaxProvider $provider): void
    {
        $this->add('tax_providers', $handle, $provider);
    }

    public function discount(string $handle, Contracts\DiscountRule $rule): void
    {
        $this->add('discount_rules', $handle, $rule);
    }

    public function checkoutCountries(string $handle, Contracts\CheckoutCountryProvider $provider): void
    {
        $this->add('checkout_country_providers', $handle, $provider);
    }

    public function checkoutStep(string $handle, Contracts\CheckoutStep $step, bool $beforeReview = false): void
    {
        if (in_array($handle, ['contact', 'delivery', 'review'], true)) {
            throw new InvalidArgumentException("Reserved checkout step: $handle");
        }
        $this->add('checkout_steps', $handle, $step);
        if ($beforeReview) {
            $this->beforeReview[] = $handle;
        }
    }

    public function beforeReviewSteps(): array
    {
        return $this->beforeReview;
    }

    public function billingFields(string $handle, callable $fields): void
    {
        $this->add('billing_fields', $handle, \Closure::fromCallable($fields));
    }

    public function requiredBillingFields(array $address, string $site, string $currency): array
    {
        $fields = [];
        foreach ($this->installed('billing_fields') as $provider) {
            $fields = array_merge($fields, $provider($address, $site, $currency));
        }
        return array_values(array_intersect(Http\CheckoutInput::ADDRESS_FIELDS, $fields));
    }

    public function orderPanel(string $handle, string $permission, callable $panel): void
    {
        $this->add('order_panels', $handle, \Closure::fromCallable($panel));
        $this->panelPermissions[$handle] = $permission;
    }

    public function orderPanels(object $order, array $receipt, $staff): array
    {
        $panels = [];
        foreach ($this->installed('order_panels') as $handle => $callback) {
            if (!$staff?->can($this->panelPermissions[$handle])) {
                continue;
            }
            $panel = $callback($order, $receipt, $staff);
            if ($panel !== null) {
                $panels[$handle] = ['title' => $panel['title'], 'label' => $panel['label'], 'url' => $panel['url']];
            }
        }
        return $panels;
    }

    public function unitPrice(string $handle, Contracts\UnitPriceProvider $provider): void
    {
        $this->add('unit_price_providers', $handle, $provider);
    }

    public function orderCharge(string $handle, Contracts\OrderChargeProvider $provider): void
    {
        if (strlen($handle) > 92) {
            throw new InvalidArgumentException('An order charge handle may contain up to 92 characters.');
        }
        $this->add('order_charge_providers', $handle, $provider);
    }

    public function registered(string $kind, string $handle): object
    {
        return $this->providers[$kind][$handle] ?? throw new InvalidArgumentException("Missing Sellissimo $kind provider: $handle");
    }

    private function add(string $kind, string $handle, object $provider): void
    {
        if (($kind === 'payments' && in_array($handle, ['free', 'voucher'], true)) || ! preg_match('/^[a-z][a-z0-9_]*$/D', $handle) || isset($this->providers[$kind][$handle])) {
            throw new InvalidArgumentException("Invalid or duplicate Sellissimo provider: $handle");
        }
        $this->providers[$kind][$handle] = $provider;
    }

    /** All installed providers, including methods disabled for new orders. */
    public function installed(string $kind): array
    {
        return $this->providers[$kind] ?? [];
    }

    /** Order matters for pricing and fee extensions. Keep it as defined by the merchant. */
    public function configured(string $kind): array
    {
        $default = config("sellissimo.$kind", []);
        $section = match ($kind) {
            'shipping', 'carriers' => 'shipping',
            'unit_price_providers', 'order_charge_providers', 'discount_rules' => 'taxes',
            default => null,
        };
        return $section ? StoreSettings::forSection(StoreSettings::GLOBAL, $section, $kind, $default) : $default;
    }

    public function enabled(string $kind, ?string $site = null): array
    {
        $handles = $kind === 'payments' && $site !== null
            ? app(PaymentSettings::class)->preferences($site)['methods'] : $this->configured($kind);
        return array_intersect_key($this->installed($kind), array_flip($handles));
    }

    public function paymentHandles(array $lines, ?array $shipping = null, ?string $site = null): array
    {
        $payments = $this->enabled('payments', $site);
        if (($shipping['pay_on_delivery'] ?? false) !== true || ! collect($lines)->contains(fn ($line) => ! $line['digital'])) {
            unset($payments['pay_on_delivery']);
        }

        return array_keys($payments);
    }

    public function paymentConfigurationWarnings(string $site): array
    {
        $warnings = [];
        foreach (app(PaymentSettings::class)->preferences($site)['methods'] as $handle) {
            $gateway = $this->providers['payments'][$handle] ?? null;
            if (!$gateway) {
                $warnings[] = __('sellissimo::shop.payment_extension_missing', ['gateway' => $handle]);
            } elseif ($gateway instanceof Contracts\ReportsPaymentConfiguration) {
                array_push($warnings, ...$gateway->configurationWarnings($site));
            }
        }

        return $warnings;
    }

    public function availablePayments(array $quote, string $site): array
    {
        CheckoutSteps::takePrivateAnswers($quote);
        if (isset($quote['amount_due'])) {
            $quote['order_total'] = $quote['total'];
            $quote['total'] = $quote['amount_due'];
        }
        $handles = $this->paymentHandles($quote['lines'], $quote['shipping'] ?? null, $site);
        return array_values(array_filter($handles, function ($handle) use ($quote, $site) {
            $gateway = $this->gateway($handle);
            return !$gateway instanceof Contracts\DeterminesPaymentAvailability || $gateway->isAvailable(Pricing::publicReceipt($quote), $site);
        }));
    }

    public function orderDetails(string $kind, string $handle, object $order, array $receipt, $staff): array
    {
        $provider = $this->providers[$kind][$handle] ?? null;
        if (! $provider instanceof Contracts\DisplaysOrderDetails || ! $staff) {
            return [];
        }
        $rows = [];
        foreach ($provider->orderDetails($order, $receipt, $staff) as $row) {
            if (! is_string($row['label'] ?? null) || ! is_string($row['value'] ?? null)) {
                continue;
            }
            $url = $row['url'] ?? null;
            $rows[] = ['label' => $row['label'], 'value' => $row['value'],
                'url' => is_string($url) && filter_var($url, FILTER_VALIDATE_URL) && parse_url($url, PHP_URL_SCHEME) === 'https' ? $url : null];
        }
        return $rows;
    }

    public function paymentReconciler(string $handle): ?Contracts\ReconcilesOrderPayments
    {
        $provider = $this->providers['payments'][$handle] ?? null;

        return $provider instanceof Contracts\ReconcilesOrderPayments ? $provider : null;
    }

    public function supportsRefunds(string $handle): bool
    {
        return $handle === 'voucher' || (isset($this->providers['payments'][$handle]) && $this->providers['payments'][$handle]->supportsRefunds());
    }

    public function gateway(string $handle): PaymentGateway
    {
        return $this->providers['payments'][$handle] ?? throw new Contracts\PaymentUnavailable("Install the payment provider for $handle to resume reconciliation.");
    }
}
