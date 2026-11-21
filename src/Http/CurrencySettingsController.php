<?php

namespace Sellissimo\Core\Http;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Sellissimo\Core\CatalogBrowse;
use Sellissimo\Core\SellingCurrencies;
use Sellissimo\Core\CheckoutCountries;
use Sellissimo\Core\Money;
use Sellissimo\Core\Pricing;
use Sellissimo\Core\Registry;
use Sellissimo\Core\StaffSites;
use Sellissimo\Core\Store;
use Sellissimo\Core\StoreSettings;
use Statamic\Dictionaries\Currencies;
use Statamic\Facades\Site;

final class CurrencySettingsController
{
    public function show(Request $request, SellingCurrencies $currencies)
    {
        $site = $request->validate(['site' => 'required|string|max:100'])['site'];
        StaffSites::authorize($site);
        $codes = (new Currencies)->optionItems();
        $target = $request->validate(['target' => ['nullable', Rule::in(collect($codes)->pluck('code'))]])['target'] ?? null;
        $inherited = config('sellissimo.currencies.'.$site);
        $active = StoreSettings::forSection($site, 'currency', 'currency', $inherited);
        $proposed = StoreSettings::forSection($site, 'currency', 'proposed_currency');
        $target ??= $proposed ?? $active;
        $installed = app(Store::class)->hasTable('settings');
        if ($request->has('sku')) {
            abort_unless($target && $installed, 422);
            return response()->json($this->sample($request, $site, $target));
        }
        $countries = app(CheckoutCountries::class);
        $locale = Site::get($site)->locale();
        $example = $target && preg_match('/^[A-Z]{3}$/D', $target)
            ? Money::display((int) round(1234.567 * 10 ** Money::fractionDigits($target)), $target, $locale) : null;
        $locked = StoreSettings::locked($site, 'currency');
        return response()->json(['active' => $active, 'proposed' => $proposed, 'inherited' => $inherited,
            'example' => $example, 'options' => collect($codes)->map(fn ($row) => ['value' => $row['code'], 'label' => $row['name'].' ('.$row['code'].')'])->values(),
            'countries' => $countries->options($site, 'billing', true),
            'defaultCountry' => $countries->settings($site)['default_billing'] ?? null, 'token' => csrf_token(), 'installed' => $installed, 'locked' => $locked,
            'overridden' => !$locked && array_key_exists('currency', StoreSettings::overrides($site, 'currency')),
            'revision' => StoreSettings::revision($site, 'currency'), 'preview' => $installed && $target ? $currencies->preview($site, $target) : null]);
    }

    private function sample(Request $request, string $site, string $currency): array
    {
        $data = $request->validate(['sku' => 'required|string|regex:/^[A-Z0-9][A-Z0-9_.-]{0,99}$/iD',
            'quantity' => 'required|integer|min:1|max:999', 'country' => ['required', Rule::in(array_keys(StoreCountries::labels()))],
            'region' => 'nullable|string|max:100', 'postal_code' => 'nullable|string|max:40', 'service' => 'nullable|string|max:150']);
        $cart = (object) ['currency' => $currency, 'customer' => null, 'site' => $site,
            'items' => json_encode([strtoupper($data['sku']) => (int) $data['quantity']], JSON_THROW_ON_ERROR)];
        $input = ['address' => array_intersect_key($data, array_flip(['country', 'region', 'postal_code'])), 'shipping' => $data['service'] ?? ''];
        $pricing = app(Pricing::class);
        $quote = $pricing->calculate($cart, $input, cartPreview: true, settingsPreview: true);
        if (!$input['shipping'] && $quote['options']) {
            $input['shipping'] = array_key_first($quote['options']);
            $quote = $pricing->calculate($cart, $input, cartPreview: true, settingsPreview: true);
        }
        $line = $quote['lines'][0] ?? null;
        if (!$line) {
            throw ValidationException::withMessages(['sku' => __('Choose a published, priced SKU with no required customer selections for this sample.')]);
        }
        $format = fn ($amount) => Money::display($amount, $currency, Site::get($site)->locale());
        return ['title' => $line['title'], 'price' => $format($line['price']), 'quantity' => $line['qty'],
            'subtotal' => $format($quote['subtotal']), 'tax' => $format($quote['tax']), 'discount' => $format($quote['discount']),
            'shipping' => $quote['shipping'] ? $format($quote['shipping']['amount'] - ($quote['shipping']['tax'] ?? 0)) : null, 'service' => $quote['shipping_method'],
            'services' => collect($quote['options'])->map(fn ($option) => $option['label'].' · '.$format($option['amount'])),
            'charges' => collect($quote['charges'] ?? [])->map(fn ($charge) => ['label' => $charge['label'], 'amount' => $format($charge['subtotal'])])->values(),
            'physical' => !$line['digital'], 'total' => $format($quote['total']), 'free' => $quote['total'] === 0,
            'methods' => app(Registry::class)->availablePayments($quote, $site),
            'issues' => array_values(array_unique([...array_values($quote['country_errors'] ?? []),
                ...array_map(fn ($field) => __('Required for tax calculation: :field', ['field' => $field]), array_unique([...$quote['missing_billing_fields'], ...$quote['missing_shipping_fields']])),
                ...($quote['stock_shortages'] ? [__('The requested quantity exceeds available stock.')] : [])]))];
    }

    public function save(Request $request, SellingCurrencies $currencies)
    {
        $data = $request->validate(['site' => 'required|string|max:100', 'action' => 'required|in:prepare,activate,reset,cancel',
            'target' => ['required_unless:action,cancel', 'nullable', Rule::in(collect((new Currencies)->optionItems())->pluck('code'))],
            'revision' => 'required|integer|min:0', 'acknowledge' => 'sometimes|boolean', 'digest' => 'nullable|string|size:64']);
        $site = $data['site'];
        StaffSites::authorize($site);
        abort_unless(app(Store::class)->hasTable('settings'), 409, __('Finish installing the store before changing currency.'));
        if (StoreSettings::locked($site, 'currency')) {
            throw ValidationException::withMessages(['currency' => __('The selling currency is managed by your hosting configuration.')]);
        }
        $action = $data['action'];
        $target = $data['target'] ?? null;
        if (in_array($action, ['activate', 'reset'], true)) {
            if ($action === 'reset' && $target !== config('sellissimo.currencies.'.$site)) {
                throw ValidationException::withMessages(['target' => __('Choose the inherited currency to restore it.')]);
            }
            $preview = $currencies->preview($site, $target);
            $changing = $target !== StoreSettings::forSection($site, 'currency', 'currency', config('sellissimo.currencies.'.$site));
            if ($changing && $preview['blocking']) {
                throw ValidationException::withMessages(['target' => $preview['blocking']]);
            }
            if ($changing && $preview['groups'] && !$request->boolean('acknowledge')) {
                throw ValidationException::withMessages(['acknowledge' => __('Review and acknowledge the affected items before changing currency.')]);
            }
            if ($changing && $preview['groups'] && !hash_equals($preview['digest'], $data['digest'] ?? '')) {
                throw ValidationException::withMessages(['digest' => __('Store availability changed. Reload the preview and review the affected items.')]);
            }
            $changes = ['currency' => $action === 'reset' ? null : $target, 'proposed_currency' => null];
        } else {
            $changes = ['proposed_currency' => $action === 'cancel' ? null : $target];
        }
        StoreSettings::save($site, 'currency', $changes, (int) $data['revision']);
        app(CatalogBrowse::class)->invalidate();
        return response()->noContent();
    }
}
