<?php

namespace Example\LocalPickup;

use Illuminate\Support\ServiceProvider as LaravelProvider;
use Sellissimo\Core\Contracts\ShippingProvider;
use Sellissimo\Core\Registry;

class ServiceProvider extends LaravelProvider
{
    public function boot(Registry $registry): void
    {
        $registry->checkoutStep('pickup_window', new class implements \Sellissimo\Core\Contracts\CheckoutStep
        {
            public function label(): string
            {
                return __('Pickup window');
            }

            public function applies(array $publicQuote, string $site): bool
            {
                return ($publicQuote['shipping_method'] ?? null) === 'pickup.counter';
            }

            public function fields(array $publicQuote, string $site): array
            {
                return ['window' => ['label' => __('Collection time'), 'type' => 'select', 'required' => true,
                    'options' => ['morning' => __('Morning, 9–12'), 'afternoon' => __('Afternoon, 13–17')]]];
            }
        });
        $registry->shipping('pickup', new class implements ShippingProvider, \Sellissimo\Core\Contracts\DisplaysOrderDetails
        {
            public function orderDetails(object $order, array $receipt, \Illuminate\Contracts\Auth\Authenticatable $staff): array
            {
                return [['label' => __('Pickup location'), 'value' => $receipt['shipping']['counter'] ?? __('Store counter')]];
            }

            public function quotes(array $physicalLines, array $address, string $currency): array
            {
                return $physicalLines ? ['counter' => ['label' => 'Collect in store', 'counter' => __('Store counter'), 'amount' => 0, 'pay_on_delivery' => true]] : [];
            }
        });
    }
}
