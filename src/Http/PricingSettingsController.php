<?php

namespace Sellissimo\Core\Http;

use Sellissimo\Core\StaffSites;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Sellissimo\Core\Money;
use Sellissimo\Core\Store;
use Statamic\Facades\CP\Toast;
use Statamic\Facades\User;

class PricingSettingsController
{
    private const RESOURCES = [
        'discounts' => ['discounts', 'code'],
        'tax-classes' => ['tax_classes', 'handle'],
        'tax-zones' => ['tax_zones', 'id'],
    ];

    public function __construct(private Store $store) {}

    public function index(string $resource, Request $request)
    {
        [$table, $key] = self::RESOURCES[$resource];
        $site = $request->query('site', StaffSites::selected());
        StaffSites::authorize(is_string($site) ? $site : null);
        $query = $this->store->table($table);
        if ($resource === 'discounts') {
            $query->where(fn ($q) => $q->whereNull('site')->orWhereIn('site', StaffSites::handles()));
            $query->select('sellissimo_discounts.*')->selectSub($this->store->table('orders')->whereIn('site', StaffSites::handles())
                ->selectRaw('count(*)')->whereColumn('coupon_code', 'sellissimo_discounts.code')->where('status', '!=', 'expired'), 'uses');
        }
        $filters = $request->validate(['q' => 'nullable|string|max:100']);
        $countries = $resource === 'tax-zones' ? StoreCountries::labels() : [];
        if (filled($filters['q'] ?? null)) {
            $term = trim($filters['q']);
            $query->where(function ($q) use ($term, $resource, $countries) {
                $q->whereLike('name', '%'.$term.'%');
                if ($resource === 'discounts') {
                    $q->orWhereLike('code', '%'.$term.'%');
                } elseif ($resource === 'tax-zones') {
                    $codes = collect($countries)->filter(fn ($name) => Str::contains($name, $term, ignoreCase: true))->keys();
                    $q->orWhereIn('country', $codes)->orWhereLike('country', $term)->orWhereLike('region', '%'.$term.'%');
                }
            });
        }
        $rows = $query->orderBy($resource === 'discounts' ? 'code' : 'name')->paginate(30)->withQueryString();
        $rows->through(function ($row) use ($resource) {
            if ($resource === 'discounts') {
                $row->state = match (true) {
                    ! $row->enabled => 'Disabled',
                    $row->starts_on && $row->starts_on > now()->toDateString() => 'Scheduled',
                    $row->ends_on && $row->ends_on < now()->toDateString() => 'Ended',
                    default => 'Enabled',
                };
                if ($row->type === 'extension') {
                    $row->type = 'extension:'.$row->rule_handle;
                }
                $row->rule_settings = (object) json_decode($row->rule_settings ?? '{}', true);
                $row->automatic = (bool) $row->automatic;
                $row->combinable = (bool) $row->combinable;
                foreach (['target_skus', 'target_products', 'target_customers'] as $field) {
                    $row->$field = json_decode($row->$field ?? '[]', true);
                }
                $row->value = $row->type === 'percentage' ? Money::decimalWithDigits($row->amount, 2) : Money::decimal($row->amount, $row->currency);
                $row->minimum = Money::decimal($row->minimum, $row->currency);
                $row->enabled = (bool) $row->enabled;
            } elseif ($resource === 'tax-zones') {
                $row->rate = Money::decimalWithDigits($row->basis_points, 2);
                $row->enabled = (bool) $row->enabled;
            } else {
                $row->inclusive = (bool) $row->inclusive;
            }

            return $row;
        });

        $targetIds = $rows->getCollection()->pluck('target_products')->filter()->flatten()->unique();
        $productNames = $targetIds->isEmpty() ? [] : \Statamic\Facades\Entry::query()
            ->whereIn('id', $targetIds->values()->all())->get()->filter(fn ($entry) => StaffSites::allowed()->has($entry->locale()))->mapWithKeys(fn ($entry) => [$entry->id() => $entry->get('title')])->all();

        $accountIds = $rows->getCollection()->pluck('target_customers')->filter()->flatten()->unique()->values();
        $accountNames = $accountIds->isEmpty() ? collect() : User::query()->whereIn('id', $accountIds->all())->get()
            ->filter(fn ($user) => app(\Sellissimo\Core\DraftCustomers::class)->eligible($user, StaffSites::selected()))
            ->mapWithKeys(fn ($user) => [(string) $user->id() => $user->email()]);

        return Inertia::render('sellissimo::PricingSettings', [
            'ruleTypes' => (object) app(\Sellissimo\Core\DiscountExtensions::class)->definitions(),
            'productNames' => (object) $productNames,
            'accountNames' => (object) $accountNames->all(),
            'accountsUrl' => cp_route('sellissimo.discount-accounts'),
            'taxPreferences' => $resource === 'discounts' ? null : (object) app(\Sellissimo\Core\TaxSettings::class)->forSite($site),
            'settingsUrl' => $resource === 'discounts' ? null : cp_route('sellissimo.settings.section', ['section' => 'taxes', 'site' => $site]),
            'taxSite' => $site, 'storewide' => StaffSites::storewide(), 'taxSettingsUrl' => cp_route('sellissimo.tax-preferences'),
            'editCode' => $resource === 'discounts' ? $request->query('edit') : null, 'resource' => $resource, 'records' => $rows, 'primaryKey' => $key,
            'currencies' => \Sellissimo\Core\SellingCurrencies::editable(),
            'filters' => $filters, 'countries' => (object) $countries,
            'classes' => $this->store->table('tax_classes')->orderBy('name')->pluck('name', 'handle'),
            'urls' => collect(array_keys(self::RESOURCES))->mapWithKeys(fn ($type) => [$type => cp_route('sellissimo.rules', ['resource' => $type, 'site' => $site])])->put('catalog', route('sellissimo.api.catalog', ['site' => $site])),
        ]);
    }

    public function accounts(Request $request, \Sellissimo\Core\DraftCustomers $customers)
    {
        $term = $request->validate(['q' => 'nullable|string|max:150'])['q'] ?? '';
        $matches = $customers->accounts(StaffSites::selected(), trim($term))->take(12)->collect();
        return response()->json($matches->map(fn ($user) => ['customer_id' => (string) $user->id(),
            'email' => $user->email(), 'address' => ['name' => $user->name() ?? '']])->values());
    }

    public function preferences(Request $request)
    {
        $data = $request->validate(['site' => 'required|string|max:100', 'physical_address' => 'required|in:billing,shipping', 'shipping_class' => 'nullable|string|max:64']);
        abort_unless(\Statamic\Facades\Site::all()->has($data['site']), 422);
        if (!empty($data['shipping_class']) && !$this->store->table('tax_classes')->where('handle', $data['shipping_class'])->exists()) {
            throw ValidationException::withMessages(['shipping_class' => 'Choose an existing tax class.']);
        }
        $this->store->table('tax_preferences')->upsert([['site' => $data['site'], 'physical_address' => $data['physical_address'], 'shipping_class' => $data['shipping_class'] ?? null]], ['site'], ['physical_address', 'shipping_class']);
        Toast::success(__('Tax settings saved.'));
        return back();
    }

    public function save(Request $request, string $resource)
    {
        [$table, $key] = self::RESOURCES[$resource];
        abort_if($resource !== 'discounts' && !StaffSites::storewide(), 403);
        $revision = $request->validate(['revision' => 'required|integer|min:0'])['revision'];
        if ($resource === 'tax-classes' && ! $revision && ! $request->filled('handle')) {
            $name = $request->validate(['name' => 'required|string|max:100'])['name'];
            $key = Str::slug($name, '_');
            $request->merge(['handle' => substr(preg_match('/^[a-z]/', $key) ? $key : 'tax_'.($key ?: Str::lower(Str::random(8))), 0, 64)]);
        }
        $values = match ($resource) {
            'discounts' => $this->discount($request),
            'tax-classes' => $request->validate([
                'handle' => ['required', 'regex:/^[a-z][a-z0-9_]{0,63}$/D'], 'name' => 'required|string|max:100', 'inclusive' => 'required|boolean',
            ]),
            'tax-zones' => $this->zone($request, (int) $revision),
        };
        $query = $this->store->table($table);
        if ((int) $revision === 0) {
            if (! $query->insertOrIgnore($values)) {
                throw ValidationException::withMessages([$key => 'This identifier already exists. Edit its existing row.']);
            }
        } elseif (! $query->where($key, $values[$key])->where('revision', $revision)->update([...$values, 'revision' => $revision + 1])) {
            throw ValidationException::withMessages(['revision' => 'This record changed. Reopen it to edit the latest version.']);
        }
        if ($resource === 'discounts') {
            \Sellissimo\Core\Search\StoreRecords::changed('discount/'.$values['code']);
        }
        Toast::success(__('Changes saved.'));

        return back();
    }

    private function discount(Request $request): array
    {
        $creating = $request->integer('revision') === 0;
        abort_if(!$request->filled('site') && !StaffSites::storewide(), 403);
        $automatic = $request->boolean('automatic');
        if (! $creating) {
            $code = $request->validate(['code' => 'required|string|max:80'])['code'];
            $existing = $this->store->table('discounts')->where('code', strtoupper($code))->first();
            if ($existing) {
                abort_if($existing->site === null && !StaffSites::storewide(), 403);
                if ($existing->site !== null) {
                    StaffSites::authorize($existing->site);
                }
                if ($request->has('automatic') && $automatic !== (bool) $existing->automatic) {
                    throw ValidationException::withMessages(['automatic' => 'Create another promotion to use a different application method.']);
                }
                $automatic = (bool) $existing->automatic;
            }
        }
        $extensions = app(\Sellissimo\Core\DiscountExtensions::class);
        $types = $extensions->definitions();
        $oldType = isset($existing) && $existing->type === 'extension' ? 'extension:'.$existing->rule_handle : null;
        $allowed = ['percentage', 'fixed', 'free_shipping', ...array_keys($types)];
        if ($oldType && ! $request->boolean('enabled')) {
            $allowed[] = $oldType;
        }
        $data = $request->validate([
            'automatic' => 'sometimes|boolean', 'combinable' => 'sometimes|boolean',
            'target_skus' => 'sometimes|array|max:100', 'target_skus.*' => 'required|string|distinct|regex:/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,99}$/D',
            'target_products' => 'sometimes|array|max:100', 'target_products.*' => 'required|string|distinct|max:150',
            'name' => [Rule::excludeIf(! $automatic), 'required', 'string', 'max:100'],
            'priority' => [Rule::excludeIf(! $automatic), 'sometimes', 'integer', 'between:1,9999'],
            'usage_limit' => [Rule::excludeIf($automatic), 'nullable', 'integer', 'between:1,1000000000'],
            'per_customer_limit' => [Rule::excludeIf($automatic), 'nullable', 'integer', 'between:1,1000000000'],
            'code' => [Rule::excludeIf($creating && $automatic), 'required', 'regex:/^[a-z0-9][a-z0-9_-]{0,79}$/iD'],
            'target_customers' => 'sometimes|array|max:250', 'target_customers.*' => 'required|string|distinct|max:150',
            'type' => ['required', Rule::in($allowed)], 'currency' => ['required', Rule::in(\Sellissimo\Core\SellingCurrencies::editable())],
            'value' => 'required_if:type,fixed,percentage|nullable|numeric', 'minimum' => 'nullable|numeric',
            'starts_on' => 'nullable|date_format:Y-m-d', 'ends_on' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('starts_on'), 'after_or_equal:starts_on')],
            'enabled' => 'required|boolean',
        ]);
        if (isset($data['target_customers'])) {
            $existingIds = json_decode($existing->target_customers ?? '[]', true);
            foreach (array_diff($data['target_customers'], $existingIds) as $id) {
                $account = User::find($id);
                if (! $account || ! app(\Sellissimo\Core\DraftCustomers::class)->eligible($account, StaffSites::selected())) {
                    throw ValidationException::withMessages(['target_customers' => 'Select a registered customer you can access.']);
                }
            }
        }
        $data['code'] = $creating && $automatic ? 'AUTO-'.strtoupper((string) Str::ulid()) : strtoupper($data['code']);
        if (array_key_exists($data['code'], config('sellissimo.discounts', []))) {
            throw ValidationException::withMessages(['code' => 'This code is already defined in the store configuration. Choose another code.']);
        }
        $digits = Money::fractionDigits($data['currency']);
        $type = $data['type'];
        $custom = str_starts_with($type, 'extension:');
        $data['rule_handle'] = $custom ? substr($type, 10) : null;
        $data['rule_settings'] = null;
        if ($custom) {
            $data['rule_settings'] = isset($types[$type])
                ? json_encode((object) $extensions->settings($types[$type]['fields'], $request->input('rule_settings', [])), JSON_THROW_ON_ERROR)
                : $existing->rule_settings;
            $data['type'] = 'extension';
        }
        $percentage = $type === 'percentage';
        $data['amount'] = ($custom || $type === 'free_shipping') ? 0 : Money::parseDecimal((string) $data['value'], $percentage ? 2 : $digits, 'value');
        if (! $custom && $type !== 'free_shipping' && ($data['amount'] < 1 || ($percentage && $data['amount'] > 10000))) {
            throw ValidationException::withMessages(['value' => 'Enter a positive discount. A percentage can be at most 100%.']);
        }
        $data['minimum'] = Money::parseDecimal((string) ($data['minimum'] ?? '0'), $digits, 'minimum');
        foreach (['target_skus', 'target_products', 'target_customers'] as $field) {
            if (isset($data[$field])) {
                $targets = $field === 'target_skus' ? array_map('strtoupper', $data[$field]) : $data[$field];
                $data[$field] = json_encode(array_values(array_unique($targets)), JSON_THROW_ON_ERROR);
            }
        }
        if ($creating) {
            $site = $request->validate(['site' => ['nullable', 'string', Rule::in(StaffSites::handles())]])['site'] ?? null;
            $data['site'] = $site;
        }
        unset($data['value']);

        return $data;
    }

    private function zone(Request $request, int $revision): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:100', 'tax_class' => ['required', Rule::in($this->store->table('tax_classes')->pluck('handle'))],
            'country' => 'required|regex:/^[A-Za-z]{2}$/D', 'region' => 'nullable|string|max:100',
            'postcode' => 'nullable|string|max:32', 'rate' => 'required|numeric|min:0|max:100', 'enabled' => 'required|boolean',
        ]);
        $data['id'] = $revision ? $request->validate(['id' => 'required|uuid'])['id'] : (string) Str::uuid();
        $data['country'] = strtoupper($data['country']);
        if (filled($data['region'] ?? null)) {
            $data['region'] = \Sellissimo\Core\PostalRegions::address($data, 'region')['region'];
        }
        $data['postcode'] = strtoupper($data['postcode'] ?? '') ?: null;
        $data['basis_points'] = Money::parseDecimal((string) $data['rate'], 2, 'rate');
        unset($data['rate']);

        return $data;
    }
}
