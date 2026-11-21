<?php

namespace Sellissimo\Core;

use Sellissimo\Core\Http\StoreCountries;

class CheckoutCountries
{
    public function __construct(private Registry $providers) {}

    public static function inherited(string $site): array
    {
        $settings = config('sellissimo.checkout_countries', []);
        return [...($settings['*'] ?? []), ...($settings[$site] ?? [])];
    }

    public function settings(string $site): array
    {
        $settings = self::inherited($site);
        foreach (['billing', 'shipping'] as $purpose) {
            $countries = StoreSettings::forSection($site, 'checkout', $purpose.'_countries');
            if ($countries !== null) {
                if ($countries === []) {
                    unset($settings[$purpose]);
                } else {
                    $settings[$purpose] = $countries;
                }
            }
            $default = StoreSettings::forSection($site, 'checkout', 'default_'.$purpose.'_country');
            if ($default !== null) {
                $settings['default_'.$purpose] = $default;
            }
        }
        $provider = StoreSettings::forSection($site, 'checkout', 'country_provider', $settings['provider'] ?? null);
        if ($provider && $provider !== '@core') {
            $settings['provider'] = $provider;
        } else {
            unset($settings['provider']);
        }
        return $settings;
    }

    /** ISO codes are the keys. A provider can further narrow the merchant's site policy. */
    public function options(string $site, string $purpose, bool $localized = false): array
    {
        $settings = $this->settings($site);
        $options = StoreCountries::labels($localized);
        if (isset($settings[$purpose])) {
            $options = array_intersect_key($options, array_flip($settings[$purpose]));
        }
        if (isset($settings['provider'])) {
            $provider = $this->providers->registered('checkout_country_providers', $settings['provider']);
            $codes = $provider->countries($site, $purpose);
            if ($codes !== null) {
                $options = array_intersect_key($options, array_flip($codes));
            }
        }
        return $options;
    }

    public function errors(string $site, array $billing, ?array $shipping, bool $physical): array
    {
        $errors = [];
        if (filled($billing['country'] ?? null) && !isset($this->options($site, 'billing')[$billing['country']])) {
            $errors['address.country'] = __('sellissimo::shop.billing_country_unavailable');
        }
        $country = ($shipping ?? $billing)['country'] ?? null;
        if ($physical && filled($country) && !isset($this->options($site, 'shipping')[$country])) {
            $field = isset($shipping) ? 'shipping_address.country' : 'address.country';
            $errors[$field] = __('sellissimo::shop.shipping_country_unavailable');
        }
        return $errors;
    }
}
