<?php

namespace Sellissimo\Core;

use Illuminate\Support\Facades\Route;
use Sellissimo\Core\Contracts\ReportsPaymentConfiguration;
use Sellissimo\Core\Contracts\ReportsShippingCurrencyCoverage;
use Statamic\Facades\Site;

final class SellingCurrencies
{
    public function __construct(private Store $store, private Catalog $catalog, private Registry $providers) {}

    public static function editable(): array
    {
        // Keep configured currencies visible while a site is staged or temporarily disabled.
        $codes = array_values(config('sellissimo.currencies', []));
        foreach (Site::all() as $site) {
            $handle = $site->handle();
            $codes[] = StoreSettings::forSection($handle, 'currency', 'currency', config('sellissimo.currencies.'.$handle));
            $codes[] = StoreSettings::forSection($handle, 'currency', 'proposed_currency');
        }
        return array_values(array_unique(array_filter($codes)));
    }

    public function preview(string $site, string $target): array
    {
        $sections = app(SettingsRegistry::class)->sections($site);
        $catalog = collect($this->catalog->currencyCoverage($site, $target));
        $priced = $catalog->where('priced', true);
        $groups = [];
        $blocking = [];
        $missing = $catalog->where('priced', false);
        $add = function (string $label, $items, bool $showRecords = true) use (&$groups) {
            $rows = collect($items);
            if ($rows->isNotEmpty()) {
                $groups[] = ['label' => __($label), 'count' => $rows->count(), 'items' => $showRecords ? $rows->take(50)->values()->all() : []];
            }
        };
        $add('Products and variants needing prices', $missing->map(fn ($row) => ['label' => $row['title'].' · '.$row['sku'], 'url' => $row['url']]));

        $unknownShipping = false;
        $services = collect();
        if ($catalog->where('digital', false)->isNotEmpty()) {
            foreach ($this->providers->enabled('shipping') as $handle => $provider) {
                if (!$provider instanceof ReportsShippingCurrencyCoverage) {
                    $unknownShipping = true;
                    $add('Delivery providers needing a test order', [['label' => $handle, 'url' => $sections['shipping']['url'] ?? null]]);
                    continue;
                }
                $services = $services->concat($provider->currencyCoverage($site, $target));
            }
            $shippingUrl = Route::has('statamic.cp.sellissimo-shipping.zones') && StaffSites::storewide() && request()->user()?->can('manage sellissimo shipping')
                ? cp_route('sellissimo-shipping.zones') : null;
            $add('Delivery services needing a rate', $services->where('priced', false)->map(fn ($row) => ['label' => $row['label'], 'url' => $shippingUrl]));
        }
        $delivery = $services->where('priced', true);
        $methods = collect($this->providers->enabled('payments', $site))->filter(fn ($provider) =>
            !$provider instanceof ReportsPaymentConfiguration || !$provider->configurationWarnings($site));
        $online = $methods->except('pay_on_delivery')->isNotEmpty();
        $cod = $methods->has('pay_on_delivery') && ($unknownShipping || $delivery->where('pay_on_delivery', true)->isNotEmpty());
        $canComplete = fn ($row) => $row['digital'] ? ($online || $row['free']) : (
            ($delivery->isNotEmpty() || $unknownShipping) &&
            ($online || $cod || ($row['free'] && ($unknownShipping || $delivery->where('free', true)->isNotEmpty())))
        );
        $destinations = app(CheckoutCountries::class);
        $billingAvailable = $destinations->options($site, 'billing') !== [];
        $shippingAvailable = $destinations->options($site, 'shipping') !== [];
        $canPlace = fn ($row) => $billingAvailable && ($row['digital'] || $shippingAvailable) && $canComplete($row);
        $eligible = $priced->filter($canPlace);
        $add('Products needing a payment or delivery method', $priced->reject($canPlace)->map(fn ($row) => ['label' => $row['title'].' · '.$row['sku'], 'url' => $row['url']]));
        if ($catalog->isNotEmpty() && $eligible->isEmpty()) {
            $blocking[] = $priced->isEmpty() ? __('Add a price in this currency to at least one published product.')
                : __('Configure a payment and delivery combination for at least one priced product.');
        }
        $old = StoreSettings::forSection($site, 'currency', 'currency', config('sellissimo.currencies.'.$site));
        if ($old && $old !== $target) {
            $add('Payment methods needing a test order in this currency', $methods->except('pay_on_delivery')->keys()->filter(fn () => $catalog->isNotEmpty())
                ->map(fn ($handle) => ['label' => ucfirst(str_replace('_', ' ', $handle)), 'url' => $sections['payments']['url'] ?? null]));
            $configuredDiscounts = collect(config('sellissimo.discounts', []))
                ->filter(fn ($rule) => isset($rule['amounts'][$old]) && !isset($rule['amounts'][$target]));
            $add('Configured discounts needing a new amount', $configuredDiscounts->keys()->map(fn ($code) => ['label' => $code]), (bool) request()->user()?->can('manage sellissimo discounts'));
            $discounts = $this->store->table('discounts')->where('currency', $old)->where('enabled', true)
                ->where(fn ($q) => $q->whereNull('site')->orWhere('site', $site))->get(['code', 'name']);
            $add('Discounts in the previous currency', $discounts->map(fn ($row) => ['label' => $row->name.' · '.$row->code,
                'url' => cp_route('sellissimo.rules', ['resource' => 'discounts', 'edit' => $row->code])]), (bool) request()->user()?->can('manage sellissimo discounts'));
            $vouchers = $this->store->table('vouchers')->where('site', $site)->where('currency', $old)->where('balance', '>', 0)->get(['id', 'ending']);
            $add('Outstanding vouchers in the previous currency', $vouchers->map(fn ($row) => ['label' => __('Voucher ending in :ending', ['ending' => $row->ending]),
                'url' => cp_route('sellissimo.vouchers', ['site' => $site, 'voucher' => $row->id])]), (bool) request()->user()?->can('manage sellissimo vouchers'));
            $open = $this->store->table('carts as c')->leftJoin('sellissimo_orders as o', 'o.cart_id', '=', 'c.id')
                ->leftJoin('sellissimo_drafts as d', 'd.cart_id', '=', 'c.id')->where('c.site', $site)->where('c.currency', $old)
                ->whereNull('o.id')->whereNull('d.cancelled_at')->whereNull('c.retry_order_id');
            $drafts = (clone $open)->whereNotNull('d.id')->pluck('d.id');
            $add('Open drafts to review', $drafts->map(fn ($id) => ['label' => __('Draft').' '.substr($id, 0, 8),
                'url' => cp_route('sellissimo.drafts.edit', $id)]), (bool) request()->user()?->can('manage sellissimo drafts'));
            $cartCount = $open->whereNull('d.id')->where('c.line_count', '>', 0)->count();
            if ($cartCount) {
                $groups[] = ['label' => __('Open carts to review'), 'count' => $cartCount, 'items' => []];
            }
        }
        $add('Fee providers needing a test order', collect($this->providers->configured('order_charge_providers'))->map(fn ($handle) => ['label' => $handle]));
        $preview = ['published' => $catalog->count(), 'priced' => $priced->count(), 'eligible' => $eligible->count(),
            'sampleSku' => $eligible->first()['sku'] ?? $priced->first()['sku'] ?? null,
            'actions' => array_values(array_filter([$sections['catalog'] ?? null, $sections['shipping'] ?? null, $sections['payments'] ?? null,
                !$billingAvailable || !$shippingAvailable ? ($sections['checkout'] ?? null) : null])),
            'paymentMethods' => $methods->keys()->values(), 'blocking' => $blocking, 'groups' => $groups];
        return [...$preview, 'digest' => hash('sha256', json_encode([$site, $old, $target, $preview], JSON_THROW_ON_ERROR))];
    }
}
