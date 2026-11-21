<?php

namespace Sellissimo\Core\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Sellissimo\Core\Contracts\ChecksPaymentConnection;
use Sellissimo\Core\PaymentSettings;
use Sellissimo\Core\OperationalSettings;
use Sellissimo\Core\Registry;
use Sellissimo\Core\StaffSites;
use Sellissimo\Core\Store;
use Statamic\Facades\CP\Toast;
use Statamic\Facades\User;

class PaymentSettingsController
{
    public function __construct(private PaymentSettings $payments, private Registry $providers, private Store $store) {}

    private function site(Request $request): string
    {
        $site = $request->input('site', StaffSites::selected());
        abort_unless(is_string($site), 404);
        StaffSites::authorize($site);
        return $site;
    }

    private function credentialsAccess(): bool
    {
        return Gate::allows('manage sellissimo payment credentials') && StaffSites::storewide();
    }

    public function index(Request $request)
    {
        $site = $this->site($request);
        $canEdit = Gate::allows('manage sellissimo payment settings');
        $canConnect = $this->credentialsAccess();
        abort_unless($canEdit || $canConnect, 403);
        $preferences = $this->payments->preferences($site);
        $methods = [];
        foreach ($this->providers->installed('payments') as $handle => $gateway) {
            $method = ['handle' => $handle, 'name' => __(Str::headline($handle)), 'connections' => []];
            if ($gateway instanceof ChecksPaymentConnection && isset(PaymentSettings::FIELDS[$handle])) {
                $method['locked'] = $this->payments->locked($handle, $site);
                $method['webhook'] = Route::has("sellissimo.$handle.webhook") ? route("sellissimo.$handle.webhook") : null;
                $method['url'] = cp_route('sellissimo.settings.payments.connection', ['provider' => $handle, 'site' => $site]);
                foreach (['deployment', 'test', 'live'] as $slot) {
                    $hosted = $slot === 'deployment';
                    $profile = $hosted ? null : $this->payments->profile($handle, $slot);
                    $id = $hosted ? $this->payments->deploymentId($handle, false) : $profile?->connection_id;
                    $row = $id ? $this->store->table('payment_connections')->where('id', $id)->first(['mode', 'account_ref', 'checked_at', 'check_passed', 'callback_at']) : null;
                    $present = $hosted ? $this->payments->deployment($handle) : ($id ? $this->payments->credentials($handle, $id) : []);
                    $paid = $id ? $this->store->table('orders')->where('site', $site)->where('gateway', $handle)->where('payment_connection_id', $id)->max('paid_at') : null;
                    $method['connections'][$slot] = ['configured' => isset($present[$handle === 'stripe' ? 'secret' : 'key']),
                        'fields' => array_keys($present), 'mode' => $row?->mode ?? ($hosted ? $this->payments->mode($handle) : $slot),
                        'revision' => (int) ($profile?->revision ?? 0), 'checked' => $row?->checked_at, 'passed' => $row?->check_passed === null ? null : (bool) $row->check_passed, 'callback' => $row?->callback_at,
                        'checkout' => $paid, 'account' => $canConnect ? $row?->account_ref : null];
                }
            }
            $methods[] = $method;
        }

        return Inertia::render('sellissimo::PaymentSettings', [
            'site' => $site, 'multisite' => \Statamic\Facades\Site::all()->count() > 1, 'sites' => StaffSites::allowed()->map->name(), 'preferences' => $preferences, 'methods' => $methods,
            'canEdit' => $canEdit, 'canConnect' => $canConnect, 'csrf' => csrf_token(), 'warnings' => $this->providers->paymentConfigurationWarnings($site),
            'url' => cp_route('sellissimo.settings.payments'), 'saveUrl' => cp_route('sellissimo.settings.payments.save', compact('site')),
            'settingsUrl' => cp_route('sellissimo.settings', compact('site')),
            'shopUrl' => OperationalSettings::storefront($site) ? route('sellissimo.shop', compact('site')) : null,
            'shippingUrl' => Gate::allows('manage sellissimo shipping') && StaffSites::storewide() && Route::has('statamic.cp.sellissimo-shipping.zones')
                ? cp_route('sellissimo-shipping.zones') : null,
        ]);
    }

    public function save(Request $request)
    {
        Gate::authorize('manage sellissimo payment settings');
        $site = $this->site($request);
        $handles = array_keys($this->providers->installed('payments'));
        $data = $request->validate(['revision' => 'required|integer|min:0', 'inherit' => 'required|boolean', 'methods' => 'present|array',
            'methods.*' => $request->boolean('inherit') ? ['string'] : ['string', 'distinct', Rule::in($handles)], 'modes' => 'nullable|array:stripe,mollie',
            'modes.*' => [Rule::in(['deployment', 'test', 'live'])], 'presentation' => 'nullable|in:hosted,embedded']);
        $values = $data['inherit'] ? [] : ['methods' => $data['methods']];
        foreach ($data['modes'] ?? [] as $provider => $mode) {
            if ($mode === 'deployment') {
                continue;
            }
            abort_unless(in_array($provider, $handles, true) && !$this->payments->locked($provider, $site)
                && $this->payments->profile($provider, $mode)?->connection_id, 422, __('Set up this connection before selecting it.'));
            $values['modes'][$provider] = $mode;
        }
        if ($data['presentation'] ?? null) {
            $values['presentation'] = $data['presentation'];
        }
        $this->payments->save($site, $values, $data['revision'], (string) User::current()->id());
        Toast::success(__('Payment settings saved.'));
        return redirect(cp_route('sellissimo.settings.payments', compact('site')));
    }

    public function connection(Request $request, string $provider)
    {
        // Remove write-only values before authorization, validation, redirects or exception handling can flash them.
        $credentials = $request->input('credentials', []);
        $request->replace($request->except('credentials'));
        abort_unless($this->credentialsAccess(), 403);
        $this->site($request);
        $gateway = $this->providers->installed('payments')[$provider] ?? null;
        abort_unless($gateway instanceof ChecksPaymentConnection && isset(PaymentSettings::FIELDS[$provider]), 404);
        $rules = ['mode' => 'required|in:deployment,test,live', 'action' => 'required|in:replace,check', 'revision' => 'required|integer|min:0'];
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }
        ['mode' => $mode, 'action' => $action, 'revision' => $revision] = $validator->validated();
        $id = null;
        if ($action === 'replace') {
            abort_if($mode === 'deployment' || config("sellissimo-$provider.lock_credentials"), 403);
            $rules = $provider === 'stripe' ? ['secret' => "required|string|max:512|regex:/^(sk|rk)_".$mode."_[a-zA-Z0-9_]+$/D",
                'publishable_key' => "nullable|string|max:512|regex:/^pk_".$mode."_[a-zA-Z0-9_]+$/D",
                'webhook_secret' => 'required|string|max:512|regex:/^whsec_[a-zA-Z0-9_]+$/D']
                : ['key' => "required|string|max:512|regex:/^".$mode."_[a-zA-Z0-9_]+$/D"];
            $validator = Validator::make(is_array($credentials) ? $credentials : [], $rules);
            if ($validator->fails()) {
                return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
            }
            $credentials = array_filter($validator->validated());
        } else {
            $id = $mode === 'deployment' ? $this->payments->deploymentId($provider) : $this->payments->profile($provider, $mode)?->connection_id;
            if (!$id) {
                return response()->json(['message' => __('Set up this connection first.')], 422);
            }
            $credentials = $this->payments->credentials($provider, $id, allowReplacement: false);
        }
        try {
            $account = $gateway::checkConnection($credentials);
        } catch (\Throwable) {
            if ($action === 'check') {
                $this->store->table('payment_connections')->where('id', $id)->update(['check_passed' => false, 'checked_at' => now()]);
            }
            return response()->json(['message' => __('The provider could not verify this connection. Check the key and its account permissions.')], 422);
        }
        if ($action === 'replace') {
            $this->payments->replace($provider, $mode, $credentials, $account, (int) $revision, (string) User::current()->id());
        } else {
            $this->store->table('payment_connections')->where('id', $id)->update(['account_ref' => $account, 'check_passed' => true, 'checked_at' => now()]);
        }
        return response()->json(['message' => __('The provider verified the connection.')]);
    }
}
