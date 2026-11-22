<?php

namespace Sellissimo\Core;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Sellissimo\Core\Http\StoreCountries;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Taxonomy;
use Statamic\Statamic;

final class OperationalSettings
{
    public static function fields(string $section, string $site): array
    {
        return match ($section) {
            'catalog' => [
                'product_collections' => self::field('Product collections', 'select', config('sellissimo.collections') ?: [config('sellissimo.collection')], 'required|array|min:1', [
                    'options' => Collection::all()->mapWithKeys(fn ($collection) => [$collection->handle() => $collection->title()])->all(),
                    'multiple' => true, 'searchable' => true,
                    'instructions' => 'Choose the collections that supply products. Their entry blueprints need Sellissimo SKU and price fields.',
                ], true),
                'product_url' => self::urlField('Custom product URL pattern', config('sellissimo.product_urls.'.$site), 'For a custom storefront, enter a site path or full URL containing {sku}. Leave empty to link to the product entry.'),
                'cross_sell_category_pairs' => self::categoryPairs($site),
                'low_stock_threshold' => self::number('Low-stock threshold', config('sellissimo.low_stock_threshold', 5), 0, 999999999, 'Notify staff when stock falls to this quantity. Set recipients in Emails and notifications.', true),
            ],
            'checkout' => self::checkoutFields($site) + self::providerFields($section, $site),
            'shipping', 'taxes' => self::providerFields($section, $site),
            'orders' => [
                'first_order_number' => self::firstOrderNumber(),
                'access_days' => self::number('Receipt link lifetime in days', config('sellissimo.access_days', 7), 1, 3650, 'Newly issued receipt and return links expire after this many days. Buyers can request a fresh receipt link.'),
                'download_days' => self::number('Download access in days', config('sellissimo.download_days', 0), 0, 3650, 'Access starts when payment clears. Enter 0 for unlimited time. Applies to new purchases.'),
                'download_limit' => self::number('Downloads per purchased line', config('sellissimo.download_limit', 0), 0, 99999, 'Enter 0 for unlimited downloads. A limit set on a product or variant takes precedence for new purchases.'),
            ],
            'emails' => [
                'recovery_automatic' => self::field('Send checkout reminders automatically', 'toggle', (bool) config('sellissimo.recovery.automatic'), 'boolean', ['instructions' => 'The scheduled store task sends one reminder to buyers who opted in.'], true),
                'recovery_inactive_minutes' => self::number('Minutes before a cart is inactive', config('sellissimo.recovery.inactive_minutes', 60), 1, 525600, 'The recovery list and reminders use this interval.', true),
                'recovery_retention_days' => self::number('Keep abandoned carts for this many days', config('sellissimo.recovery.retention_days', 90), 1, 3650, 'The scheduled store task removes expired abandoned carts and unused artwork.', true),
                'recovery_storefront_url' => self::urlField('Custom storefront recovery URL', config('sellissimo.recovery.storefront_urls.'.$site), 'Used when the bundled storefront is disabled. Leave empty to use the site homepage.'),
            ],
            default => [],
        };
    }

    private static function categoryPairs(string $site): array
    {
        $options = [];
        foreach (Taxonomy::findByHandle('sellissimo_categories')?->queryTerms()->get() ?? [] as $term) {
            $options[$term->slug()] = $term->in($site)?->title() ?: $term->slug();
        }

        return self::field('Complementary category pairs', 'grid', config("sellissimo.cross_sell_category_pairs.$site", []), 'array|max:100', [
            'mode' => 'table', 'max_rows' => 100, 'add_row' => __('Add pair'), 'fields' => [
                ['handle' => 'source', 'field' => ['type' => 'select', 'display' => __('Product category'), 'options' => $options, 'searchable' => true]],
                ['handle' => 'target', 'field' => ['type' => 'select', 'display' => __('Complementary category'), 'options' => $options, 'searchable' => true]],
            ],
        ]);
    }

    private static function firstOrderNumber(): array
    {
        $store = app(Store::class);
        $issued = $store->hasTable('orders') && ($store->table('orders')->exists() || ($store->hasTable('order_sequences')
            && $store->table('order_sequences')->where('name', 'orders')->value('last_number') > 0));
        $help = $issued ? 'Orders have already been issued. The order number sequence is fixed.' : 'Set this before issuing your first order. All sites share the same sequence.';

        // Keep long order numbers as text in the browser so JavaScript preserves every digit.
        return self::field('First order number', 'text', (string) config('sellissimo.first_order_number', 1000),
            'required|regex:/^[1-9][0-9]{0,17}$/D', ['input_mode' => 'numeric', 'instructions' => __($help)], true) + ['readonly' => $issued];
    }

    private static function field(string $label, string $type, mixed $default, string $rules, array $config = [], bool $shared = false): array
    {
        return ['field' => ['type' => $type, 'display' => __($label), ...$config], 'default' => $default, 'rules' => $rules, 'shared' => $shared];
    }

    private static function orderedField(string $label, array $options, array $default, string $instructions, bool $shared, int $minimum = 0): array
    {
        $config = ['mode' => 'table', 'reorderable' => true, 'min_rows' => $minimum, 'max_rows' => count($options),
            'instructions' => $instructions, 'add_row' => __('Add option'), 'fields' => [
                ['handle' => 'choice', 'field' => ['type' => 'select', 'display' => __('Option'), 'options' => $options, 'searchable' => true]],
            ]];

        return self::field($label, 'grid', $default, 'array|min:'.$minimum.'|max:100', $config, $shared) + ['ordered' => true];
    }

    private static function number(string $label, mixed $default, int $min, int $max, string $instructions, bool $shared = false): array
    {
        return self::field($label, 'integer', (int) $default, "required|integer|between:$min,$max", ['min' => $min, 'max' => $max, 'instructions' => __($instructions)], $shared);
    }

    private static function urlField(string $label, mixed $default, string $instructions): array
    {
        return self::field($label, 'text', $default, 'nullable|string|max:2048', ['instructions' => __($instructions)]);
    }

    private static function checkoutFields(string $site): array
    {
        $countries = StoreCountries::labels(true);
        $inherited = CheckoutCountries::inherited($site);
        $steps = ['contact' => __('sellissimo::shop.contact'), 'delivery' => __('sellissimo::shop.delivery'), 'review' => __('sellissimo::shop.review')];
        foreach (app(Registry::class)->installed('checkout_steps') as $handle => $step) {
            $steps[$handle] = $step->label();
        }
        $fields = [
            'storefront' => self::field('Enable the bundled storefront', 'toggle', (bool) config('sellissimo.storefront'), 'boolean', ['instructions' => __('Serve the catalog and checkout at /:prefix. Custom storefronts can use the shop API and template tags.', ['prefix' => trim(config('sellissimo.prefix'), '/')])]),
            'guest_checkout' => self::field('Allow guest checkout', 'toggle', (bool) config('sellissimo.guest_checkout', true), 'boolean', ['instructions' => __('Turn off to require a customer account before placing an order. Customer registration requires Statamic Pro.')]),
            'account_login_url' => self::urlField('Customer sign-in page', config("sellissimo.account_urls.$site.login"), 'Leave empty to use the built-in sign-in form.'),
            'account_register_url' => self::urlField('Customer registration page', config("sellissimo.account_urls.$site.register"), 'Leave empty to use Statamic registration when available.'),
            'checkout_steps' => self::orderedField('Checkout steps in display order', $steps, config('sellissimo.checkout_steps'), __('Start with contact, include delivery and finish with review. Delivery is skipped for digital orders.'), false, 3),
        ];
        foreach (['billing' => 'Billing', 'shipping' => 'Shipping'] as $purpose => $label) {
            $fields[$purpose.'_countries'] = self::field($label.' countries', 'select', $inherited[$purpose] ?? [], 'array', ['multiple' => true, 'searchable' => true, 'options' => $countries, 'instructions' => __('Leave empty to allow all countries. Delivery still requires a matching shipping service.')]);
            $fields['default_'.$purpose.'_country'] = self::field('Default '.strtolower($label).' country', 'select', $inherited['default_'.$purpose] ?? null, 'nullable|string|size:2', ['options' => $countries, 'clearable' => true, 'searchable' => true]);
        }
        foreach (['terms_page' => 'Terms and conditions page', 'privacy_page' => 'Privacy policy page'] as $key => $label) {
            $fields[$key] = self::field($label, 'entries', config("sellissimo.checkout_policy.$site.$key"), 'nullable|string|max:255', ['max_items' => 1, 'mode' => 'select', 'instructions' => __('Select a published page for this site. Buyers see a link before placing an order.')]);
        }
        foreach (['require_terms' => 'Require acceptance of terms', 'require_privacy' => 'Require acknowledgement of the privacy notice', 'offer_newsletter' => 'Offer newsletter subscription', 'payment_obligation' => 'Use explicit payment obligation button'] as $key => $label) {
            $fields[$key] = self::field($label, 'toggle', (bool) config("sellissimo.checkout_policy.$site.$key", false), 'boolean');
        }
        $fields['payment_notice'] = self::field('Additional payment notice', 'text', config("sellissimo.checkout_policy.$site.payment_notice"), 'nullable|string|max:500');

        return $fields;
    }

    private const PROVIDERS = [
        'shipping' => ['shipping', 'shipping', 'Shipping providers', 'Enable extensions that quote delivery or pickup at checkout.', 'manage sellissimo shipping'],
        'carriers' => ['shipping', 'carriers', 'Shipping label providers', 'Enable carriers for new label bookings. Previously booked labels remain accessible.', 'manage sellissimo shipping'],
        'unit_price_providers' => ['taxes', 'unit_price_providers', 'Unit price rules in application order', 'Choose pricing extensions in the order their rules should run. A later rule can change the price returned by an earlier one.', SettingsRegistry::MANAGE],
        'order_charge_providers' => ['taxes', 'order_charge_providers', 'Order fee rules in display order', 'Choose the extensions that add fees at checkout, in the order buyers should see them.', SettingsRegistry::MANAGE],
        'discount_rules' => ['taxes', 'discount_rules', 'Discount rule extensions', 'Enable extension rules for discount campaigns.', 'manage sellissimo discounts'],
        'tax_provider' => ['taxes', 'tax_providers', 'Tax calculation provider', 'Choose the service that calculates tax for this site.', 'manage sellissimo taxes'],
        'country_provider' => ['checkout', 'checkout_country_providers', 'Country restriction provider', 'An extension can further limit the countries selected above.', SettingsRegistry::MANAGE],
    ];

    private static function providerFields(string $section, string $site): array
    {
        $registry = app(Registry::class);
        $fields = [];
        foreach (self::PROVIDERS as $key => [$group, $kind, $label, $help, $permission]) {
            if ($group !== $section) {
                continue;
            }
            $single = in_array($key, ['tax_provider', 'country_provider'], true);
            $default = match ($key) {
                'tax_provider' => config("sellissimo.tax_settings.$site.provider") ?: '@core',
                'country_provider' => CheckoutCountries::inherited($site)['provider'] ?? '@core',
                default => config("sellissimo.$kind", []),
            };
            $options = [];
            foreach (array_keys($registry->installed($kind)) as $handle) {
                $options[$handle] = __(Str::headline($handle));
            }
            $scope = $single ? $site : StoreSettings::GLOBAL;
            $current = StoreSettings::forSection($scope, $section, $key, $default);
            $missing = array_diff(array_unique([...(array) $current, ...(array) $default]), array_keys($options), ['@core']);
            if (! $options && ! $missing) {
                continue;
            }
            foreach ($missing as $handle) {
                $options[$handle] = __(':provider (extension unavailable)', ['provider' => Str::headline($handle)]);
            }
            if ($single) {
                $options = ['@core' => $key === 'tax_provider' ? __('Built-in tax rules') : __('Use the countries selected above')] + $options;
            }
            $instructions = __($help);
            if ($missing) {
                $instructions .= ' '.__('Reinstall unavailable extensions or remove them from this selection.');
            }
            $ordered = in_array($key, ['unit_price_providers', 'order_charge_providers'], true);
            $definition = $ordered
                ? self::orderedField($label, $options, $default, $instructions, true)
                : self::field($label, 'select', $default, $single ? 'required|string|max:100' : 'array|max:100',
                    ['options' => $options, 'multiple' => ! $single, 'searchable' => true, 'instructions' => $instructions], ! $single);
            $fields[$key] = $definition + ['permission' => $permission];
        }

        return $fields;
    }

    public static function validateChanges(string $section, string $site, array $changes): void
    {
        foreach (self::PROVIDERS as $key => [$group, $kind]) {
            if ($section !== $group || ! isset($changes[$key])) {
                continue;
            }
            $allowed = array_keys(app(Registry::class)->installed($kind));
            if (in_array($key, ['tax_provider', 'country_provider'], true)) {
                $allowed[] = '@core';
            }
            self::allowedValues($key, (array) $changes[$key], $allowed);
        }
        if ($section === 'catalog' && isset($changes['product_collections'])) {
            self::allowedValues('product_collections', $changes['product_collections'], Collection::all()->map->handle()->all());
        }
        if ($section === 'catalog' && isset($changes['cross_sell_category_pairs'])) {
            $options = array_keys(self::categoryPairs($site)['field']['fields'][0]['field']['options']);
            foreach ($changes['cross_sell_category_pairs'] as $row) {
                self::allowedValues('cross_sell_category_pairs', [$row['source'] ?? '', $row['target'] ?? ''], $options);
            }
        }
        if ($section === 'orders' && array_key_exists('first_order_number', $changes)) {
            $store = app(Store::class);
            $last = $store->table('order_sequences')->where('name', 'orders')->lockForUpdate()->value('last_number');
            if ($last > 0 || $store->table('orders')->exists()) {
                self::invalid('first_order_number', 'The first order number can only be set before the first order is issued.');
            }
        }
        foreach (['account_login_url', 'account_register_url', 'recovery_storefront_url', 'product_url'] as $key) {
            if ($url = $changes[$key] ?? null) {
                if ($key === 'product_url') {
                    if (substr_count($url, '{sku}') !== 1 || strpbrk(str_replace('{sku}', '', $url), '{}') !== false) {
                        self::invalid($key, 'Include {sku} once in the product URL pattern.');
                    }
                    $url = str_replace('{sku}', 'SKU-123', $url);
                }
                $relative = preg_match('~^/(?!/)[^\\\\\x00-\x20]*$~D', $url);
                if (! $relative && (str_contains($url, '\\') || ! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true) || parse_url($url, PHP_URL_USER) !== null)) {
                    self::invalid($key, 'Use a site path beginning with / or a full HTTP or HTTPS URL.');
                }
            }
        }
        if ($section !== 'checkout') {
            return;
        }
        if (isset($changes['guest_checkout']) && ! $changes['guest_checkout'] && ! Statamic::pro()) {
            self::invalid('guest_checkout', 'Requiring customer accounts needs Statamic Pro.');
        }
        if (! empty($changes['storefront'])) {
            $problem = self::storefrontConflict($site);
            if ($problem) {
                self::invalid('storefront', $problem);
            }
        }
        foreach (['terms_page' => 'require_terms', 'privacy_page' => 'require_privacy'] as $key => $toggle) {
            if (! array_key_exists($key, $changes) && ! array_key_exists($toggle, $changes)) {
                continue;
            }
            $page = array_key_exists($key, $changes) ? $changes[$key] : CheckoutPolicy::setting($site, $key);
            $required = $changes[$toggle] ?? CheckoutPolicy::setting($site, $toggle, false);
            if (($required || $page) && (! $page || ! self::pageUrl($page, $site))) {
                self::invalid($key, 'Choose a published page with a public URL on this site.');
            }
        }
        if (isset($changes['checkout_steps'])) {
            $order = $changes['checkout_steps'];
            self::allowedValues('checkout_steps', $order, ['contact', 'delivery', 'review', ...array_keys(app(Registry::class)->installed('checkout_steps'))]);
            if (reset($order) !== 'contact' || end($order) !== 'review' || ! in_array('delivery', $order, true)) {
                self::invalid('checkout_steps', 'Start with contact, include delivery and finish with review.');
            }
        }
        $inherited = CheckoutCountries::inherited($site);
        $codes = StoreCountries::labels();
        foreach (['billing', 'shipping'] as $purpose) {
            $listKey = $purpose.'_countries';
            $defaultKey = 'default_'.$purpose.'_country';
            if (! array_key_exists($listKey, $changes) && ! array_key_exists($defaultKey, $changes)) {
                continue;
            }
            if (isset($changes[$listKey])) {
                self::allowedValues($listKey, $changes[$listKey], array_keys($codes));
            }
            $list = array_key_exists($listKey, $changes) ? ($changes[$listKey] ?? $inherited[$purpose] ?? []) : StoreSettings::forSection($site, 'checkout', $listKey, $inherited[$purpose] ?? []);
            $default = array_key_exists($defaultKey, $changes) ? ($changes[$defaultKey] ?? $inherited['default_'.$purpose] ?? null) : StoreSettings::forSection($site, 'checkout', $defaultKey, $inherited['default_'.$purpose] ?? null);
            if ($default && (! isset($codes[$default]) || ($list && ! in_array($default, $list, true)))) {
                self::invalid($defaultKey, 'Choose an allowed country as the default.');
            }
        }
    }

    private static function allowedValues(string $key, array $values, array $allowed): void
    {
        if (! array_is_list($values) || collect($values)->contains(fn ($value) => ! is_string($value) || ! in_array($value, $allowed, true)) || count(array_unique($values)) !== count($values)) {
            self::invalid($key, 'Choose each item once from the available options.');
        }
    }

    private static function invalid(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => __($message)]);
    }

    public static function catalogReadiness(string $site): array
    {
        $issues = [];
        foreach (Catalog::collections() as $handle) {
            $collection = Collection::findByHandle($handle);
            if (! $collection || ! $collection->sites()->contains($site)) {
                $issues[] = ['message' => __('Collection :name is unavailable on this site.', ['name' => $handle]), 'url' => null];

                continue;
            }
            foreach ($collection->entryBlueprints() as $blueprint) {
                $fields = $blueprint->fields()->all();
                if (! $fields->has('sellissimo_variants') && ! ($fields->has('sellissimo_prices') && $fields->has('sellissimo_sku'))) {
                    $issues[] = ['message' => __('Add product SKU and price fields to :blueprint.', ['blueprint' => $blueprint->title()]), 'url' => request()->user()?->can('configure fields') ? cp_route('blueprints.collections.edit', [$collection, $blueprint]) : null];
                }
            }
        }

        return $issues;
    }

    public static function storefront(string $site): bool
    {
        return (bool) StoreSettings::forSection($site, 'checkout', 'storefront', config('sellissimo.storefront')) && Route::has('sellissimo.shop');
    }

    public static function storefrontConflict(string $site): ?string
    {
        if (! Route::has('sellissimo.shop')) {
            return __('The configured storefront path is unavailable. Check the path and rebuild the application route cache.');
        }
        $prefix = trim(config('sellissimo.prefix'), '/');
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->getName() ?? '', 'sellissimo.') && ($route->uri() === $prefix || str_starts_with($route->uri(), $prefix.'/'))) {
                return __('Another application route already uses /:path. Choose a different storefront path in the hosting configuration.', ['path' => $route->uri()]);
            }
        }
        $path = '/'.$prefix;
        $entries = Entry::query()->where('site', $site)->where(function ($query) use ($path) {
            $query->where('uri', $path)->orWhere('uri', 'like', $path.'/%');
        })->get();
        foreach ($entries as $entry) {
            $uri = trim((string) $entry->uri(), '/');
            if ($uri === $prefix || str_starts_with($uri, $prefix.'/')) {
                return __('A page already uses /:path. Choose a different storefront path in the hosting configuration.', ['path' => $uri]);
            }
        }

        return null;
    }

    public static function accountUrls(string $site): array
    {
        $accounts = config('sellissimo.account_urls.'.$site, []);
        foreach (['login', 'register'] as $kind) {
            $accounts[$kind] = StoreSettings::forSection($site, 'checkout', 'account_'.$kind.'_url', $accounts[$kind] ?? null);
        }

        return $accounts;
    }

    public static function policyLinks(string $site): array
    {
        $links = [];
        foreach (['terms_page', 'privacy_page'] as $name) {
            if ($id = CheckoutPolicy::setting($site, $name)) {
                if ($url = self::pageUrl($id, $site)) {
                    $links[$name] = $url;
                }
            }
        }

        return $links;
    }

    private static function pageUrl(string $id, string $site): ?string
    {
        $entry = Entry::find($id)?->in($site);

        return $entry?->status() === 'published' ? $entry->absoluteUrl() : null;
    }

    public static function shared(string $key, string $config, mixed $fallback = null): mixed
    {
        $section = match ($key) {
            'low_stock_threshold' => 'catalog',
            'first_order_number' => 'orders',
            'recovery_automatic', 'recovery_inactive_minutes', 'recovery_retention_days' => 'emails',
        };

        return StoreSettings::forSection(StoreSettings::GLOBAL, $section, $key, config('sellissimo.'.$config, $fallback));
    }

    public static function accessDays(object $order): int
    {
        $receipt = json_decode($order->receipt ?? '{}', true);

        return (int) ($receipt['access_days'] ?? config('sellissimo.access_days', 7));
    }
}
