<?php

namespace Sellissimo\Core;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Statamic\CP\Navigation\Nav;
use Statamic\Facades\Collection;
use Statamic\Facades\Preference;

final class StoreNavigation
{
    public static function build(Nav $nav): void
    {
        $sites = StaffSites::allowed();
        if ($sites->isEmpty()) {
            return;
        }

        // Legacy leaves have flat IDs. Statamic can move a flat item into a personal
        // section, but cannot infer its new parent from that ID to remove it there.
        $moved = $qualified = [];
        foreach (Arr::dot((array) Preference::get('nav')) as $path => $value) {
            if (!preg_match('/(?:^|\.)((?:store::)?(sellissimo-[\w-]+))(?::[a-z0-9]{6})?(?:\.[^.]+)?$/iD', $path, $match)) {
                continue;
            }
            if ($match[1] !== $match[2]) {
                $qualified[$match[2]] = $match[1];
            }
            if (in_array($value, ['move', '@move'], true)) {
                $moved[$match[1]] = true;
            }
        }

        $requested = request()->routeIs('statamic.cp.sellissimo.*', 'statamic.cp.sellissimo-shipping.*') ? request()->query('site') : null;
        $siteQuery = is_string($requested) && $sites->has($requested) ? ['site' => $requested] : [];
        $site = $siteQuery['site'] ?? StaffSites::selected();

        self::group($nav, $moved, $qualified, $siteQuery, 'Orders', 'order-workspace', 'shopping-cart', [
            ['All orders', 'orders', 'sellissimo.orders', 'view sellissimo orders'],
            ['Draft orders', 'drafts', 'sellissimo.drafts', 'manage sellissimo drafts'],
            ['Returns', 'returns', 'sellissimo.returns', 'view sellissimo returns'],
            ['Checkout recovery', 'recovery', 'sellissimo.recovery', 'support sellissimo orders'],
        ]);

        $catalog = [];
        // Store settings must remain reachable when the commerce database is offline.
        try {
            $handles = Catalog::collections();
        } catch (\PDOException|\InvalidArgumentException $e) {
            report($e);
            $handles = [];
        }
        foreach (array_unique($handles) as $handle) {
            $collection = Collection::findByHandle($handle);
            if (!$collection || !Gate::allows('view', $collection)) {
                continue;
            }
            $eligible = $collection->sites()->intersect($sites->keys());
            if ($eligible->isEmpty()) {
                continue;
            }
            $catalog[] = $nav->item($collection->title())->id('sellissimo-collection-'.$handle)
                ->route('collections.show', ['collection' => $handle, 'site' => $eligible->contains($site) ? $site : $eligible->first()])
                ->can('view', $collection);
        }

        self::group($nav, $moved, $qualified, $siteQuery, 'Catalog & inventory', 'catalog', 'collections', [
            ['Inventory', 'inventory', 'sellissimo.inventory', 'manage sellissimo inventory'],
            ['Locations and movements', 'locations', 'sellissimo.locations', 'manage sellissimo inventory'],
            ['Back-in-stock demand', 'stock-demand', 'sellissimo.stock-demand', 'manage sellissimo inventory'],
            ['Catalog import and export', 'import', 'sellissimo.import', 'import sellissimo products'],
        ], $catalog);

        $nav->create('Customers')->id($qualified['sellissimo-customers'] ?? 'sellissimo-customers')->section('Store')->icon('user-avatar')->route('sellissimo.customers', $siteQuery)->can('manage sellissimo customers');
        self::group($nav, $moved, $qualified, $siteQuery, 'Discounts & vouchers', 'offers', 'money-cashier-price-tag', [
            ['Discounts', 'discounts', 'sellissimo.rules', 'manage sellissimo discounts', ['resource' => 'discounts']],
            ['Gift vouchers', 'vouchers', 'sellissimo.vouchers', 'manage sellissimo vouchers'],
        ]);
        $nav->create('Sales reports')->id($qualified['sellissimo-reports'] ?? 'sellissimo-reports')->section('Store')->icon('money-cashier-calculator')->route('sellissimo.sales', $siteQuery)->can('view sellissimo sales');

        $sections = app(SettingsRegistry::class)->sections($site);
        if ($sections) {
            $settings = $nav->create('Settings')->id($qualified['sellissimo-settings'] ?? 'sellissimo-settings')->section('Store')->icon('cog')->route('sellissimo.settings', $siteQuery);
            // A bookmarked specialist editor can live outside the settings URL hierarchy.
            // Give the native sidebar and breadcrumbs its real destination on those pages.
            $context = match (Route::currentRouteName()) {
                'statamic.cp.sellissimo-shipping.zones' => ['shipping', 'Shipping zones', 'manage sellissimo shipping', StaffSites::storewide()],
                'statamic.cp.sellissimo.webhooks' => ['system', 'Webhooks', 'manage sellissimo webhooks', StaffSites::storewide()],
                'statamic.cp.sellissimo.features' => [request()->query('from') === 'emails' ? 'emails' : 'checkout', request()->query('from') === 'emails' ? 'Staff email recipients' : 'Storefront features', SettingsRegistry::MANAGE, true],
                default => null,
            };
            if ($context && isset($sections[$context[0]]) && $context[3] && Gate::allows($context[2])) {
                $query = $siteQuery;
                if (request()->routeIs('statamic.cp.sellissimo.features')) {
                    $query['from'] = $context[0];
                }
                $settings->children([
                    $nav->item($context[1])->id('sellissimo-context-'.$context[0])->url(route(Route::currentRouteName(), $query))->can($context[2]),
                ], false);
            }
        }
    }

    private static function group(Nav $nav, array $moved, array $qualified, array $siteQuery, string $label, string $id, string $icon, array $destinations, array $children = []): void
    {
        foreach ($destinations as $destination) {
            [$title, $key, $route, $permission] = $destination;
            if (Gate::allows($permission)) {
                $leafId = 'sellissimo-'.$key;
                $parameters = ($destination[4] ?? []) + ($key === 'locations' ? [] : $siteQuery);
                $children[] = $nav->item($title)->id($qualified[$leafId] ?? $leafId)->route($route, $parameters)->can($permission);
            }
        }
        $children = array_values(array_filter($children, function ($item) use ($moved, $icon) {
            if (!isset($moved[$item->id()])) {
                return true;
            }
            $item->section('Store')->icon($icon);
            return false;
        }));
        if ($children) {
            $nav->create($label)->section('Store')->id('sellissimo-'.$id)->icon($icon)
                ->url($children[0]->url())->children($children, false);
        }
    }
}
