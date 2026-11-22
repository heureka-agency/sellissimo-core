<?php

namespace Sellissimo\Core\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Sellissimo\Core\{Catalog, CatalogBrowse, Money, OperationalSettings, PricingRules, Registry, SettingsRegistry, SettingsStatus, StaffSites, Store, StoreMail, StoreSettings, TaxSettings};
use Statamic\Facades\Blueprint;
use Statamic\Facades\Site;
use Statamic\Statamic;

class StoreSettingsController
{
    public function __construct(private SettingsRegistry $registry, private Store $store) {}

    private function site(Request $request): string
    {
        $site = $request->validate(['site' => 'sometimes|required|string|max:100'])['site'] ?? StaffSites::selected();
        StaffSites::authorize($site);
        return $site;
    }

    public function index(Request $request, SettingsStatus $status)
    {
        $site = $this->site($request);
        $sections = $this->registry->sections($site);
        abort_unless($sections, 403);
        return Inertia::render('sellissimo::SettingsOverview', [
            'site' => $site, 'sites' => StaffSites::allowed()->map->name(), 'multisite' => Statamic::pro() && Site::all()->count() > 1,
            'sections' => array_values($sections), 'overviewUrl' => cp_route('sellissimo.settings'), 'summary' => $status->summary($site, $sections),
        ]);
    }

    public function section(Request $request, string $section, SettingsStatus $status)
    {
        $site = $this->site($request);
        $current = $this->registry->sections($site)[$section] ?? abort(403);
        if ($this->registry->destinationRoute($section) !== null) {
            return redirect()->to($current['url']);
        }
        $reachable = $status->databaseReachable();
        $fields = $reachable ? $this->registry->fields($section, $site) : [];
        $configs = $values = $sources = [];
        $editable = $reachable && $this->store->hasTable('settings');
        foreach ($fields as $key => $definition) {
            $scope = ($definition['shared'] ?? false) ? StoreSettings::GLOBAL : $site;
            $readonly = !$editable || StoreSettings::locked($scope, $key) || ($scope === StoreSettings::GLOBAL && !StaffSites::storewide())
                || ($definition['readonly'] ?? false);
            $configs[$key] = $definition['field'];
            if ($readonly) {
                $configs[$key]['visibility'] = 'read_only';
            }
            $saved = array_key_exists($key, StoreSettings::overrides($scope, $section));
            $source = StoreSettings::locked($scope, $key) ? __('Managed by hosting configuration') : ($saved ? __('Saved in the Control Panel') : __('Using the default'));
            if ($scope === StoreSettings::GLOBAL && !StaffSites::storewide()) {
                $source .= '. '.__('Staff with access to all sites can edit this value.');
            }
            $configs[$key]['instructions'] = trim(__($configs[$key]['instructions'] ?? '').' '.($scope === StoreSettings::GLOBAL ? __('All sites').' · ' : '').$source);
            $values[$key] = StoreSettings::forSection($scope, $section, $key, $definition['default']);
            if ($definition['ordered'] ?? false) {
                $values[$key] = array_map(fn ($handle) => ['choice' => $handle], $values[$key]);
            }
            $sources[$key] = ['saved' => $saved, 'readonly' => $readonly, 'label' => $configs[$key]['display']];
        }
        $blueprint = Blueprint::makeFromFields($configs);
        $prepared = $blueprint->fields()->addValues($values)->preProcess();
        $issues = $section === 'catalog' && $editable ? OperationalSettings::catalogReadiness($site) : [];
        if ($editable && $section === 'catalog' && $this->store->hasTable('site_features') && ($currency = StoreSettings::forSection($site, 'currency', 'currency', config("sellissimo.currencies.$site")))) {
            foreach (app(Catalog::class)->currencyCoverage($site, $currency) as $row) {
                if (!$row['priced']) {
                    $issues[] = ['message' => __('Add a :currency price for :product.', ['currency' => $currency, 'product' => $row['title'].' ('.$row['sku'].')']), 'url' => $row['url']];
                }
            }
        }
        $diagnostics = match (true) {
            $section === 'system' && Gate::allows(SettingsRegistry::MANAGE) => $status->diagnostics($site),
            $section === 'details' => [__('Language and locale (Statamic site)') => Site::get($site)->locale(), __('Time zone (application)') => config('app.timezone')],
            $section === 'checkout' => [__('Customer accounts') => Statamic::pro() ? __('Statamic Pro available') : __('Statamic Pro required')],
            default => [],
        };
        return Inertia::render('sellissimo::SettingsSection', [
            'site' => $site, 'siteName' => Site::get($site)->name(), 'multisite' => Statamic::pro() && Site::all()->count() > 1,
            'sites' => StaffSites::allowed()->map->name(), 'sectionUrl' => cp_route('sellissimo.settings.section', compact('section')),
            'section' => $section, 'title' => $current['title'], 'description' => $current['description'],
            'overviewUrl' => cp_route('sellissimo.settings', compact('site')), 'saveUrl' => cp_route('sellissimo.settings.save', compact('section', 'site')),
            'blueprint' => $blueprint->toPublishArray(), 'values' => $prepared->values(), 'meta' => $prepared->meta(),
            'sources' => $sources, 'revisions' => $editable ? $this->revisions($site, $section) : ['site' => 0, 'shared' => 0], 'installed' => $editable,
            'links' => $reachable ? $this->registry->links($section, $site) : [], 'issues' => $issues, 'diagnostics' => $diagnostics,
            'currencyUrl' => $section === 'details' && $editable ? cp_route('sellissimo.settings.currency', compact('site')) : null,
            'csrf' => csrf_token(),
            'checkUrl' => ($section === 'emails' || ($section === 'system' && StaffSites::storewide())) && Gate::allows(SettingsRegistry::MANAGE) && $editable
                ? cp_route('sellissimo.settings.check', ['check' => $section === 'emails' ? 'email' : 'storage', 'site' => $site]) : null,
            'testRecipient' => $section === 'emails' ? $request->user()->email() : null,
            'taxPreviewUrl' => $section === 'taxes' && Gate::allows('manage sellissimo taxes') && $editable ? cp_route('sellissimo.settings.tax-preview', compact('site')) : null,
            'taxCountries' => $section === 'taxes' ? StoreCountries::labels(true) : [],
            'taxClasses' => $section === 'taxes' && $editable ? $this->store->table('tax_classes')->pluck('name','handle')->all() + ['standard' => __('Standard')] : [],
        ]);
    }

    private function revisions(string $site, string $section): array
    {
        return ['site' => StoreSettings::revision($site, $section), 'shared' => StoreSettings::revision(StoreSettings::GLOBAL, $section)];
    }

    public function save(Request $request, string $section)
    {
        $site = $this->site($request);
        abort_unless(isset($this->registry->sections($site)[$section]), 403);
        $definitions = $this->registry->fields($section, $site);
        abort_unless($definitions, 403);
        abort_unless($this->store->hasTable('settings'), 503);
        $data = $request->validate(['changes' => 'sometimes|array|max:100', 'reset' => 'sometimes|array|max:100', 'reset.*' => 'string|max:100',
            'revisions.site' => 'required|integer|min:0', 'revisions.shared' => 'required|integer|min:0']);
        $changes = $data['changes'] ?? [];
        $keys = array_unique([...array_keys($changes), ...($data['reset'] ?? [])]);
        foreach ($keys as $key) {
            abort_unless(isset($definitions[$key]), 422);
            $shared = $definitions[$key]['shared'] ?? false;
            abort_if(($shared && !StaffSites::storewide()) || ($definitions[$key]['readonly'] ?? false), 403);
        }
        $rules = [];
        foreach (array_keys($changes) as $key) {
            $field = $definitions[$key]['field'];
            if (($definitions[$key]['ordered'] ?? false) && is_array($changes[$key])) {
                $changes[$key] = array_map(fn ($row) => is_array($row) ? ($row['choice'] ?? null) : $row, $changes[$key]);
            }
            if ($field['type'] === 'entries' && ($field['max_items'] ?? null) === 1) {
                if (is_array($changes[$key]) && (count($changes[$key]) > 1 || !array_is_list($changes[$key]))) {
                    throw ValidationException::withMessages([$key => __('Choose one entry.')]);
                }
                $changes[$key] = is_array($changes[$key]) ? ($changes[$key][0] ?? null) : $changes[$key];
            }
            $rules[$key] = $definitions[$key]['rules'];
        }
        $changes = validator($changes, $rules)->validate();
        foreach ($changes as $key => &$value) {
            $type = $definitions[$key]['field']['type'];
            if ($value !== null && $type === 'integer') {
                $value = (int) $value;
            }
            if ($value !== null && $type === 'toggle') {
                $value = (bool) $value;
            }
        }
        unset($value);
        foreach ($data['reset'] ?? [] as $key) {
            $changes[$key] = null;
        }
        $this->store->db()->transaction(function () use ($site, $section, $changes, $definitions, $data) {
            $this->registry->validate($section, $site, $changes);
            $scopes = [];
            foreach ($changes as $key => $value) {
                $scope = ($definitions[$key]['shared'] ?? false) ? StoreSettings::GLOBAL : $site;
                $scopes[$scope][$key] = $value;
            }
            ksort($scopes);
            foreach ($scopes as $scope => $values) {
                StoreSettings::save($scope, $section, $values, $data['revisions'][$scope === StoreSettings::GLOBAL ? 'shared' : 'site']);
            }
        }, 3);
        if ($section === 'catalog' && $changes) {
            app(CatalogBrowse::class)->invalidate();
        }
        return response()->json(['saved' => true, 'revisions' => $this->revisions($site, $section)]);
    }

    public function check(Request $request, string $check, SettingsStatus $status)
    {
        $site = $this->site($request);
        Gate::authorize(SettingsRegistry::MANAGE);
        if ($check === 'email') {
            $recipient = $request->user()->email();
            abort_unless(filter_var($recipient, FILTER_VALIDATE_EMAIL), 422);
            try {
                app(StoreMail::class)->send('settings_test', $site, $recipient, cp_route('sellissimo.settings.section', ['section' => 'emails', 'site' => $site]));
                SettingsStatus::observe($site, 'mail', 'Mail transport accepted the test');
            } catch (\Throwable $error) {
                report($error);
                SettingsStatus::observe($site, 'mail', 'Mail transport failed');
                throw ValidationException::withMessages(['check' => __('The test message failed. Check the mail transport and sender address.')]);
            }
            return response()->json(['message' => __('The mail transport accepted a test message for :email.', ['email' => $recipient])]);
        }
        abort_unless(StaffSites::storewide(), 403);
        try {
            $ok = $status->checkStorage();
        } catch (\Throwable $error) {
            report($error);
            $ok = false;
        }
        SettingsStatus::observe(StoreSettings::GLOBAL, 'storage', $ok ? 'Private read and write verified' : 'Private storage check failed');
        if (!$ok) {
            throw ValidationException::withMessages(['check' => __('Check the configured private disk, its visibility and write access.')]);
        }
        return response()->json(['message' => __('Private storage passed the read, write and visibility checks.')]);
    }

    public function taxPreview(Request $request)
    {
        $site = $this->site($request);
        Gate::authorize('manage sellissimo taxes');
        $input = $request->validate(['country' => ['required', Rule::in(array_keys(StoreCountries::labels()))], 'region' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:40', 'amount' => 'required|string|max:15', 'tax_class' => 'required|string|max:64']);
        $currency = app(Catalog::class)->currency($site);
        $amount = Money::parseDecimal($input['amount'], Money::fractionDigits($currency), 'amount');
        abort_if($amount > 99999999, 422);
        $settings = app(TaxSettings::class)->forSite($site);
        $rules = app(PricingRules::class);
        $address = array_intersect_key($input, array_flip(['country','region','postal_code']));
        $provider = isset($settings['provider']) ? app(Registry::class)->registered('tax_providers', $settings['provider']) : null;
        $required = $provider ? $provider->requiredFields($address, [$input['tax_class']], $site) : $rules->billingFields($address, [$input['tax_class']]);
        foreach ($required as $field) {
            if (blank($address[$field] ?? null)) {
                throw ValidationException::withMessages([$field => __('Enter :field for this tax calculation.', ['field' => $field])]);
            }
        }
        $rates = $provider ? $provider->rates($address, $site) : $rules->taxRates($address);
        $inclusive = in_array($input['tax_class'], $rules->inclusiveClasses(), true);
        $tax = PricingRules::roundedRate($amount, $rates[$input['tax_class']] ?? 0, $inclusive);
        return response()->json(['tax' => Money::display($tax, $currency), 'total' => Money::display($amount + ($inclusive ? 0 : $tax), $currency),
            'basis' => ($settings['physical_address'] ?? 'billing') === 'shipping' ? __('Shipping address') : __('Billing address'), 'inclusive' => $inclusive]);
    }
}
