<?php

namespace Sellissimo\Core;

use Illuminate\Validation\ValidationException;

class Pricing
{
    public function __construct(private Catalog $catalog, private Registry $providers, private PricingRules $rules) {}

    public function calculate(object $cart, array $input, bool $checkout = false, bool $placingOrder = false, ?object $amendment = null, bool $cartPreview = false, bool $settingsPreview = false): array
    {
        if ($settingsPreview) {
            throw_unless($cartPreview && ! $checkout && ! $placingOrder && empty($cart->id), \LogicException::class, 'A settings preview requires a temporary cart.');
        } else {
            app(StockHolds::class)->expireDue();
        }
        $commerceFields = app(CommerceFields::class);
        $scope = CommerceFields::scope($cart);
        $orderValues = $commerceFields->values('order', $input['order_fields'] ?? json_decode($cart->order_fields ?? '{}', true), $scope, true, $checkout);
        $currency = $cart->currency;
        abort_if(! $settingsPreview && empty($cart->retry_order_id) && $this->catalog->currency($cart->site) !== $currency, 409, __('The selling currency changed. Review the cart to rebuild it.'));
        $retry = PaymentRetry::originalReceipt($cart);
        [$lines, $unavailable, $products, $quantities, $subtotal] = $this->prepareLines(
            $cart, $commerceFields, $scope, $retry, $amendment, $checkout || $cartPreview, $cartPreview
        );
        $address = PostalRegions::address($input['address'] ?? []);
        $destination = PostalRegions::address($input['shipping_address'] ?? $address);
        if ($amendment) {
            foreach (json_decode($amendment->receipt, true)['lines'] as $line) {
                if (Inventory::tracked($line) && isset($products[$line['sku']]['available'])) {
                    $products[$line['sku']]['available'] += (int) $line['qty'];
                }
            }
        }
        $draft = app(StockHolds::class)->forCart($cart);
        foreach (json_decode($draft->reserved_stock ?? '[]', true) as $sku => $locations) {
            if (isset($products[$sku]['available'])) {
                $products[$sku]['available'] += array_sum($locations);
            }
        }
        $shortages = [];
        foreach ($quantities as $sku => $quantity) {
            if ($quantity > $products[$sku]['available']) {
                $shortages[$sku] = $products[$sku]['available'];
            }
        }
        $settings = app(TaxSettings::class)->forSite($cart->site);
        $destinationTax = ($settings['physical_address'] ?? 'billing') === 'shipping';
        $shippingClass = $settings['shipping_class'] ?? null;
        $provider = isset($settings['provider']) ? $this->providers->registered('tax_providers', $settings['provider']) : null;
        $fields = fn ($location, $classes) => $provider ? $provider->requiredFields($location, $classes, $cart->site) : $this->rules->billingFields($location, $classes);
        $ratesFor = fn ($location) => $provider ? $provider->rates($location, $cart->site) : $this->rules->taxRates($location);
        $physical = array_values(array_filter($lines, fn ($item) => ! $item['digital']));
        $countryErrors = app(CheckoutCountries::class)->errors($cart->site, $address, $input['shipping_address'] ?? null, (bool) $physical);
        if ($countryErrors && ! $cartPreview) {
            throw ValidationException::withMessages($countryErrors);
        }
        $physicalClasses = array_values(array_unique(array_column($physical, 'tax_class')));
        if ($shippingClass && $physical) {
            $physicalClasses[] = $shippingClass;
        }
        $digitalClasses = array_column(array_filter($lines, fn ($line) => $line['digital']), 'tax_class');
        $billingClasses = $destinationTax ? $digitalClasses : [...$digitalClasses, ...$physicalClasses];
        $billingRequirements = $this->providers->requiredBillingFields($address, $cart->site, $currency);
        $billing = array_values(array_unique([...$fields($address, $billingClasses), ...$billingRequirements]));
        $deliveryFields = $destinationTax && $physical ? $fields($destination, $physicalClasses) : [];
        if (! $cartPreview && $physical) {
            PostalRegions::address($destination, isset($input['shipping_address']) ? 'shipping_address.region' : 'address.region');
        }
        if (! $cartPreview && in_array('region', $billing, true)) {
            PostalRegions::address($address, 'address.region');
        }
        $missing = array_values(array_filter($billing, fn ($field) => blank($address[$field] ?? null)));
        $missingDelivery = array_values(array_filter($deliveryFields, fn ($field) => blank($destination[$field] ?? null)));
        if ($checkout && ($missing || $missingDelivery)) {
            $label = $missing ? 'billing' : 'shipping';
            $fields = array_map(fn ($field) => __('sellissimo::shop.'.$field), $missing ?: $missingDelivery);
            abort(422, __('sellissimo::shop.enter_'.$label.'_fields_to_calculate_tax', ['fields' => implode(' '.__('sellissimo::shop.and').' ', $fields)]));
        }
        if ($checkout && ! $placingOrder && $shortages) {
            abort(409, __('sellissimo::shop.insufficient_stock_for_sku', ['sku' => implode(', ', array_keys($shortages))]));
        }
        $code = strtoupper($input['discount'] ?? '');
        $eligibleLines = array_filter($lines, fn ($line) => empty($line['voucher_product']));
        abort_if($lines && ! $eligibleLines && $code !== '', 422, __('sellissimo::shop.the_cart_contains_no_items_eligible_for_this_discount'));
        $offers = $eligibleLines || $code !== '' ? $this->rules->offers($code, $currency, $eligibleLines, $input['email'] ?? null, $cart->customer ?? null, $placingOrder, $amendment?->id, $cart->site) : [];
        $discount = array_sum(array_column($offers, 'discount'));
        $freeShipping = in_array(true, array_column($offers, 'free_shipping'), true);
        $billingRates = $ratesFor($address);
        $physicalRates = $destinationTax && $destination !== $address ? $ratesFor($destination) : $billingRates;
        $inclusiveClasses = $this->rules->inclusiveClasses();
        $tax = 0;
        foreach ($lines as &$line) {
            $share = array_sum(array_map(fn ($offer) => $offer['allocations'][$line['key']] ?? 0, $offers));
            $rates = $line['digital'] ? $billingRates : $physicalRates;
            $inclusive = in_array($line['tax_class'], $inclusiveClasses, true);
            $line['tax_rate'] = $rates[$line['tax_class']] ?? null;
            $line['tax_inclusive'] = $inclusive;
            $line['discount'] = $share;
            $line['tax'] = PricingRules::roundedRate($line['subtotal'] - $share, $rates[$line['tax_class']] ?? 0, $inclusive);
            if ($inclusive) {
                $line['subtotal'] -= $line['tax'];
                $subtotal -= $line['tax'];
            }
            $line['total'] = $line['subtotal'] - $share + $line['tax'];
            $tax += $line['tax'];
        }
        unset($line);
        $physical = array_values(array_filter($lines, fn ($item) => ! $item['digital']));
        $options = [];
        if ($physical && ! $countryErrors && ! empty($destination['country'])) {
            foreach ($this->providers->enabled('shipping') as $handle => $provider) {
                foreach ($provider->quotes($physical, $destination, $currency) as $key => $quote) {
                    throw_unless(is_int($quote['amount']) && $quote['amount'] >= 0 && is_string($quote['label']), 'Invalid shipping quote.');
                    if ($freeShipping) {
                        $quote['original_amount'] = $quote['amount'];
                        $quote['amount'] = 0;
                    }
                    $options[$handle.'.'.$key] = $quote;
                }
            }
        }
        if ($physical && ! empty($destination['country']) && isset($input['custom_shipping'])) {
            $fixed = $input['custom_shipping'];
            if ($freeShipping) {
                $fixed['original_amount'] = $fixed['amount'];
                $fixed['amount'] = 0;
            }
            $options['draft.custom'] = $fixed;
        }
        if ($shippingClass) {
            foreach ($options as &$option) {
                $inclusive = in_array($shippingClass, $inclusiveClasses, true);
                $option['tax'] = PricingRules::roundedRate($option['amount'], $physicalRates[$shippingClass] ?? 0, $inclusive);
                if (! $inclusive) {
                    $option['amount'] += $option['tax'];
                }
            }
            unset($option);
        }
        $method = $input['shipping'] ?? '';
        $shipping = $options[$method] ?? null;
        if ($shipping !== null) {
            $shipping['tax_class'] = $shippingClass;
            $shipping['tax_rate'] = $physicalRates[$shippingClass] ?? null;
            $shipping['tax_inclusive'] = in_array($shippingClass, $inclusiveClasses, true);
        }
        abort_if($checkout && ! $lines, 422, __('sellissimo::shop.cart_is_empty'));
        $completeAddress = collect(DeliveryFields::required($destination))->every(fn ($field) => filled($destination[$field] ?? null));
        $deliveryReady = ! $physical || ($shipping && $completeAddress);
        abort_if(! $deliveryReady && $checkout, 422, __('sellissimo::shop.choose_delivery_and_supply_a_complete_address'));

        $receipt = [
            ...($countryErrors ? ['country_errors' => $countryErrors] : []),
            ...($unavailable ? ['unavailable' => $unavailable] : []),
            ...($orderValues ? ['order_fields' => $commerceFields->snapshot('order', $orderValues)] : []),
            'delivery_ready' => $deliveryReady, 'missing_shipping_fields' => $missingDelivery, 'billing_fields' => $billing, 'missing_billing_fields' => $missing, 'stock_shortages' => $shortages,
            'lines' => $lines, 'currency' => $currency, 'subtotal' => $subtotal, 'code' => $code, 'promotion' => $offers[0] ?? null, 'promotions' => $offers,
            'discount' => $discount, 'tax' => $tax + ($shipping['tax'] ?? 0), 'address' => $address, 'options' => $options,
            ...($physical && filled($input['shipping_instructions'] ?? null) ? ['shipping_instructions' => $input['shipping_instructions']] : []),
            ...($physical && isset($input['shipping_address']) ? ['shipping_address' => $destination] : []),
            'shipping' => $shipping, 'shipping_method' => $shipping ? $method : null,
            'total' => $subtotal - $discount + $tax + ($shipping['amount'] ?? 0),
        ];
        $charges = app(OrderCharges::class)->quote($receipt, $cart->site, $cart->customer ?? null, $billingRates, $inclusiveClasses);
        if ($charges) {
            $receipt['charges'] = $charges;
            $receipt['tax'] += array_sum(array_column($charges, 'tax'));
            $receipt['total'] += array_sum(array_column($charges, 'total'));
            $classes = array_values(array_unique(array_filter(array_column($charges, 'tax_class'))));
            if (array_diff($classes, $billingClasses)) {
                $billing = array_values(array_unique([...$fields($address, array_values(array_unique([...$billingClasses, ...$classes]))), ...$billingRequirements]));
                $receipt['billing_fields'] = $billing;
                if (! $cartPreview && in_array('region', $billing, true)) {
                    PostalRegions::address($address, 'address.region');
                }
                $receipt['missing_billing_fields'] = array_values(array_filter($billing, fn ($field) => blank($address[$field] ?? null)));
                if ($checkout && $receipt['missing_billing_fields']) {
                    $names = array_map(fn ($key) => __('sellissimo::shop.'.$key), $receipt['missing_billing_fields']);
                    abort(422, __('sellissimo::shop.enter_billing_fields_to_calculate_tax', ['fields' => implode(' '.__('sellissimo::shop.and').' ', $names)]));
                }
            }
        }
        if (! empty($input['voucher']) && ! $amendment) {
            $receipt['voucher'] = app(GiftVouchers::class)->quote($receipt, $input['voucher'], $cart->site, $placingOrder);
            $receipt['amount_due'] = $receipt['total'] - $receipt['voucher']['amount'];
        }
        if ($amendment) {
            $snapshots = json_decode($amendment->receipt, true)['checkout_steps'] ?? [];
        } else {
            $steps = app(CheckoutSteps::class);
            $snapshots = $steps->snapshot($steps->definitions($receipt, $cart->site), $input['checkout_steps'] ?? [], $placingOrder, $cartPreview);
        }
        if ($snapshots) {
            $receipt['checkout_steps'] = $snapshots;
        }
        if ($placingOrder && $retry) {
            $sameAmounts = (int) $receipt['total'] === (int) $retry['total']
                && (int) ($receipt['amount_due'] ?? $receipt['total']) === (int) ($retry['amount_due'] ?? $retry['total']);
            abort_unless($sameAmounts, 409, __('The original payment amount can no longer be reproduced. Start a new order to review current prices.'));
        }

        return $receipt;
    }

    /** Count the lines a buyer could review without requesting tax, delivery or payment estimates. */
    public function cartQuantity(object $cart): int
    {
        [$lines] = $this->prepareLines($cart, app(CommerceFields::class), CommerceFields::scope($cart),
            PaymentRetry::originalReceipt($cart), null, validateFields: true, skipUnavailable: true);

        return array_sum(array_column($lines, 'qty'));
    }

    private function prepareLines(object $cart, CommerceFields $commerceFields, ?string $scope, ?array $retry,
        ?object $amendment, bool $validateFields, bool $skipUnavailable): array
    {
        $items = json_decode($cart->items, true, flags: JSON_THROW_ON_ERROR);
        foreach ($retry['lines'] ?? [] as $line) {
            $key = $line['key'] ?? $line['sku'];
            if (isset($items[$key])) {
                $items[$key] = is_array($items[$key]) ? $items[$key] : ['sku' => $line['sku'], 'qty' => $items[$key], 'options' => []];
                $items[$key]['staff_price'] = $line['price'];
            }
        }
        $skus = array_map(fn ($key, $item) => is_array($item) ? $item['sku'] : (string) $key, array_keys($items), $items);
        $products = $this->catalog->products($cart->site, false, array_unique($skus), $cart->currency);
        if ($retry) {
            // A published SKU may now have only its new selling price. Reuse its definitions, then restore the quoted amount.
            $missing = array_diff($skus, array_keys($products));
            $current = $missing ? $this->catalog->products($cart->site, false, array_values($missing)) : [];
            foreach ($retry['lines'] as $line) {
                $sku = $line['sku'];
                $product = $products[$sku] ?? $current[$sku] ?? null;
                if (! $product || $product['entry_id'] !== ($line['entry_id'] ?? null)) {
                    unset($products[$sku]);

                    continue;
                }
                $products[$sku] = [...$product, 'currency' => $cart->currency, 'price' => $line['price'], 'compare_at_price' => null];
            }
        }
        $priceProviders = array_map(fn ($handle) => $this->providers->registered('unit_price_providers', $handle), $this->providers->configured('unit_price_providers'));
        $priceContext = ['site' => $cart->site, 'currency' => $cart->currency, 'customer' => $cart->customer ?? null, 'items' => []];
        foreach ($items as $key => $item) {
            $priceContext['items'][$key] = is_array($item)
                ? ['sku' => $item['sku'], 'qty' => $item['qty'], 'options' => $item['options'] ?? []]
                : ['sku' => (string) $key, 'qty' => $item, 'options' => []];
        }
        $lines = [];
        $subtotal = 0;
        $quantities = [];
        $originalLines = $amendment ? collect(json_decode($amendment->receipt, true)['lines'])->keyBy(fn ($line) => $line['key'] ?? $line['sku']) : collect();
        $returnDays = app(ReturnPolicy::class)->days($cart->site);
        $unavailable = [];
        $titles = json_decode($cart->line_titles ?? '{}', true);
        foreach ($items as $key => $stored) {
            $sku = is_array($stored) ? $stored['sku'] : (string) $key;
            $qty = is_array($stored) ? $stored['qty'] : $stored;
            try {
                $line = $this->priceLine($cart, $stored, $sku, $key, $qty, $products[$sku] ?? null, $originalLines->get($key),
                    $returnDays, $commerceFields, $scope, $validateFields, $priceProviders, $priceContext);
            } catch (ValidationException $error) {
                if (! $skipUnavailable) {
                    throw $error;
                }
                $unavailable[] = ['key' => $key, 'sku' => $sku, 'title' => $titles[$key] ?? $products[$sku]['title'] ?? $sku];

                continue;
            }
            if ($skipUnavailable && ! empty($products[$sku]['option_fields'])) {
                $line['can_edit_options'] = true;
            }
            $lines[] = $line;
            $subtotal += $line['subtotal'];
            if (Inventory::tracked($line)) {
                $quantities[$sku] = ($quantities[$sku] ?? 0) + $qty;
            }
        }

        return [$lines, $unavailable, $products, $quantities, $subtotal];
    }

    private function priceLine(object $cart, mixed $stored, string $sku, string $key, int $qty, ?array $product, ?array $original,
        ?int $returnDays, CommerceFields $commerceFields, ?string $scope, bool $validateFields, array $priceProviders, array $priceContext): array
    {
        $currency = $cart->currency;
        $product = is_array($stored) && isset($stored['service'], $stored['staff_price'])
            ? ['entry_id' => null, 'sku' => $sku, 'title' => $stored['service'], 'product_title' => $stored['service'],
                'currency' => $currency, 'digital' => true, 'downloadable' => false, 'file' => null, 'tax_class' => 'standard',
                'option_fields' => [], 'price' => $stored['staff_price']]
            : ($product ?? throw ValidationException::withMessages(['sku' => "Unavailable SKU: $sku"]));
        if (! empty($product['voucher_product'])) {
            $recipient = is_array($stored) ? ($stored['options']['gift_recipient'] ?? null) : null;
            if ($recipient && ! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages(['options.gift_recipient' => __('Enter a valid voucher recipient email.')]);
            }
        } elseif (is_array($stored) && isset($stored['staff_price'])) {
            $product['price'] = $stored['staff_price'];
        } else {
            foreach ($priceProviders as $provider) {
                $amount = $provider->unitPrice($product['price'], $product, $priceContext['items'][$key], $priceContext);
                if ($amount === null) {
                    continue;
                }
                throw_unless($amount >= 0 && $amount <= 99999999, \UnexpectedValueException::class, 'Unit prices must be between 0 and 99,999,999 minor units.');
                $product['price'] = $amount;
            }
        }
        if (isset($product['compare_at_price']) && $product['compare_at_price'] <= $product['price']) {
            $product['compare_at_price'] = null;
        }

        if ($validateFields && (! $original || $qty > $original['qty'])) {
            app(PurchasePrerequisites::class)->check($product, $cart->site, $cart->customer ?? null);
        }
        unset($product['prerequisite_id']);
        if ($original && $original['sku'] === $sku && ! empty($original['file'])) {
            $product['file'] = $original['file'];
            $product['download_limit'] = $original['download_limit'] ?? 0;
            $product['download_days'] = $original['download_days'] ?? 0;
            $product['digital'] = $product['downloadable'] = true;
        }
        $fields = $product['option_fields'];
        unset($product['option_fields'], $product['available']);
        if (! $product['digital']) {
            $policy = $original ? ($original['return_policy'] ?? []) : array_filter(['days' => $returnDays, 'final_sale' => $product['final_sale'] ?? false]);
            unset($product['final_sale']);
            if ($policy) {
                $product['return_policy'] = $policy;
            }
        }
        $values = LineOptions::validate($fields, is_array($stored) ? $stored['options'] : []);
        $options = [];
        foreach ($values as $handle => $text) {
            $options[] = ['handle' => $handle, 'label' => $fields[$handle]['label'], 'value' => $text];
        }
        $custom = $commerceFields->values('line', is_array($stored) ? ($stored['line_fields'] ?? []) : [], $scope, true, $validateFields);
        $value = $product['price'] * $qty;

        return [...$product, 'key' => (string) $key, 'options' => $options, 'qty' => $qty, 'subtotal' => $value, ...($custom ? ['line_fields' => $commerceFields->snapshot('line', $custom)] : [])];
    }

    public static function fingerprint(array $receipt): string
    {
        $receipt = self::publicReceipt($receipt);
        unset($receipt['delivery_ready'], $receipt['missing_shipping_fields'], $receipt['options'], $receipt['billing_fields'], $receipt['missing_billing_fields'], $receipt['stock_shortages']);

        return hash('sha256', json_encode($receipt, JSON_THROW_ON_ERROR));
    }

    public static function publicReceipt(array $receipt): array
    {
        unset($receipt['return_url'], $receipt['voucher']['id'], $receipt['checkout_consents']);
        if (isset($receipt['order_fields'])) {
            $receipt['order_fields'] = CommerceFields::visible($receipt['order_fields']);
        }
        foreach ($receipt['lines'] as &$line) {
            unset($line['file'], $line['can_edit_options']);
            if (isset($line['line_fields'])) {
                $line['line_fields'] = CommerceFields::visible($line['line_fields']);
            }
        }

        return $receipt;
    }
}
