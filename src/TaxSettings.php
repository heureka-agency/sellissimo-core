<?php

namespace Sellissimo\Core;

class TaxSettings
{
    public function __construct(private Store $store) {}

    public function forSite(string $site): array
    {
        $settings = config("sellissimo.tax_settings.$site", []);
        if ($row = $this->store->table('tax_preferences')->where('site', $site)->first()) {
            $settings['physical_address'] = $row->physical_address;
            $settings['shipping_class'] = $row->shipping_class;
        }

        $provider = StoreSettings::forSection($site, 'taxes', 'tax_provider', $settings['provider'] ?? null);
        if ($provider && $provider !== '@core') {
            $settings['provider'] = $provider;
        } else {
            unset($settings['provider']);
        }
        return $settings;
    }
}
