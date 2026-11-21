<?php

namespace Sellissimo\Core;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Sellissimo\Core\Contracts\ReportsShippingCurrencyCoverage;

final class SettingsStatus
{
    public function __construct(private Store $store, private Registry $providers) {}

    public static function observe(string $scope, string $name, string $result): void
    {
        $store = app(Store::class);
        if ($store->hasTable('settings_checks')) {
            $store->table('settings_checks')->upsert([['scope' => $scope, 'name' => $name, 'result' => $result, 'checked_at' => now()]],
                ['scope', 'name'], ['result', 'checked_at']);
        }
    }

    public static function heartbeat(): void
    {
        try {
            self::observe(StoreSettings::GLOBAL, 'scheduler', 'Scheduler ran');
        } catch (\PDOException|\InvalidArgumentException $error) {
            // An unavailable commerce database must not interrupt other scheduled work on the site.
            report($error);
        }
    }

    public function summary(string $site, array $sections): array
    {
        if (!$this->databaseReachable()) {
            return ['currency' => null, 'storefront' => null, 'payments' => null, 'delivery' => null,
                'tasks' => [['message' => __('Check the connection to the commerce database.'), 'url' => $sections['system']['url'] ?? null]]];
        }
        $currency = StoreSettings::forSection($site, 'currency', 'currency', config("sellissimo.currencies.$site"));
        $storefront = StoreSettings::forSection($site, 'checkout', 'storefront', config('sellissimo.storefront'));
        $summary = ['currency' => $currency ?: __('Choose a currency'), 'storefront' => $storefront ? __('Enabled') : __('Headless storefront'),
            'payments' => null, 'delivery' => null, 'tasks' => []];
        $add = function (string $section, string $message, ?string $url = null) use (&$summary, $sections) {
            if (isset($sections[$section])) {
                $summary['tasks'][] = ['message' => $message, 'url' => $url ?: $sections[$section]['url']];
            }
        };
        if (!$this->store->hasTable('settings') || !$this->store->hasTable('orders')) {
            $add('system', __('Finish installing the store database.'));
            return $summary;
        }
        if ($storefront && ($problem = OperationalSettings::storefrontConflict($site))) {
            $summary['storefront'] = __('Check storefront path');
            $add('checkout', $problem);
        }
        if (!$currency) {
            $add('details', __('Choose a selling currency.'));
            return $summary;
        }
        $methods = $this->providers->enabled('payments', $site);
        $rows = collect(app(Catalog::class)->currencyCoverage($site, $currency));
        $physical = $rows->contains('digital', false);
        $rates = [];
        $unverified = false;
        foreach ($this->providers->enabled('shipping') as $carrier) {
            if ($carrier instanceof ReportsShippingCurrencyCoverage) {
                array_push($rates, ...$carrier->currencyCoverage($site, $currency));
            } else {
                $unverified = true;
            }
        }
        $priced = collect($rates)->where('priced', true);
        $summary['delivery'] = match (true) {
            $rows->isEmpty() => __('Awaiting first product'),
            !$physical => __('Digital delivery'),
            $unverified => __('Check a delivery quote'),
            $priced->count() === 1 => __('1 eligible delivery service'),
            default => __(':count eligible delivery services', ['count' => $priced->count()]),
        };
        $summary['payments'] = collect(array_keys($methods))->map(fn ($method) => __(str($method)->replace('_', ' ')->headline()->toString()))->implode(', ') ?: __('No methods enabled');
        if (!$rows->count()) {
            $create = isset($sections['catalog']) ? app(SettingsRegistry::class)->catalogCreationLinks($site) : [];
            $add('catalog', __('Add your first sellable product.'), $create[0]['url'] ?? null);
        }
        $missing = $rows->where('priced', false);
        if ($missing->count()) {
            $add('catalog', __('Add :currency prices to :count SKUs.', ['currency' => $currency, 'count' => $missing->count()]), $missing->count() === 1 ? $missing->first()['url'] : null);
        }
        if ($physical && $priced->isEmpty()) {
            $add('shipping', $unverified ? __('Test a delivery quote in :currency.', compact('currency')) : __('Add a delivery rate in :currency.', compact('currency')));
        }
        if ($rows->where('priced', true)->contains('free', false)) {
            if (!$methods) {
                $add('payments', __('Enable a payment method for paid products.'));
            } elseif (array_keys($methods) === ['pay_on_delivery'] && (!$physical || (!$unverified && !$priced->contains('pay_on_delivery', true)))) {
                $add('payments', __('Choose a payment method eligible for your products and delivery services.'));
            }
        }
        if (array_keys($methods) === ['pay_on_delivery'] && $physical && $rows->where('digital', true)->where('priced', true)->contains('free', false)) {
            $add('payments', __('Enable an online payment method for digital-only purchases.'));
        }
        if (isset($sections['payments'])) {
            foreach ($this->providers->paymentConfigurationWarnings($site) as $warning) {
                $add('payments', $warning);
            }
        }
        foreach (array_keys($sections) as $section) {
            foreach (app(SettingsRegistry::class)->extensionChecks($section, $site) as $check) {
                $add($section, $check['message'], $check['url'] ?? null);
            }
        }

        return $summary;
    }

    public function databaseReachable(): bool
    {
        try {
            $this->store->hasTable('settings');
            return true;
        } catch (\PDOException|\InvalidArgumentException $error) {
            report($error);
            return false;
        }
    }

    public function diagnostics(string $site): array
    {
        $reachable = $this->databaseReachable();
        $installed = $reachable && $this->store->hasTable('settings_checks');
        $observations = $installed ? $this->store->table('settings_checks')->whereIn('scope', [$site, StoreSettings::GLOBAL])->get()->keyBy('name') : collect();
        $observed = function (string $key, string $help) use ($observations): string {
            $row = $observations->get($key);
            return $row ? __($row->result).' · '.$row->checked_at : __('Not checked yet').' · '.__($help);
        };
        $providers = [];
        foreach (['payments', 'shipping', 'carriers', 'tax_providers', 'checkout_steps', 'unit_price_providers', 'order_charge_providers'] as $kind) {
            foreach (array_keys($this->providers->installed($kind)) as $handle) {
                $providers[] = __(str($handle)->headline()->toString()).' ('.__(str($kind)->replace('_', ' ')->toString()).')';
            }
        }
        return [
            __('Commerce database') => !$reachable ? __('Check the configured database connection and access permissions.')
                : ($installed && $this->store->hasTable('orders') ? __('Available') : __('Run php please sellissimo:install to create the store tables.')),
            __('Storefront route') => Route::has('sellissimo.shop') ? '/'.trim(config('sellissimo.prefix'), '/') : __('Review the storefront path for a route conflict.'),
            __('Mail transport') => config('mail.default').' · '.$observed('mail', 'Send a test message from Emails and notifications.'),
            __('Scheduler last seen') => $observed('scheduler', 'Configure the Laravel scheduler to run every minute.'),
            __('Private file storage') => config('sellissimo.download_disk').' · '.$observed('storage', 'Run the storage check below.'),
            __('Installed providers') => implode(', ', $providers) ?: __('None installed'),
        ];
    }

    public function checkStorage(): bool
    {
        $disk = Storage::disk(config('sellissimo.download_disk'));
        $key = '.sellissimo-checks/'.Str::uuid();
        $token = Str::random(24);
        if (!$disk->put($key, $token, 'private')) {
            return false;
        }
        try {
            $private = $disk->get($key) === $token && $disk->getVisibility($key) === 'private';
        } finally {
            $removed = $disk->delete($key);
        }

        return $private && $removed;
    }
}
