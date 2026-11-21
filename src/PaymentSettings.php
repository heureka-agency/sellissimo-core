<?php

namespace Sellissimo\Core;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Sellissimo\Core\Contracts\PaymentUnavailable;

class PaymentSettings
{
    public const FIELDS = ['stripe' => ['secret', 'publishable_key', 'webhook_secret'], 'mollie' => ['key']];

    public function __construct(private Store $store) {}

    public function preferences(string $site): array
    {
        $row = $this->store->hasTable('payment_preferences') ? $this->store->table('payment_preferences')->where('site', $site)->first() : null;
        $values = $row?->preferences ? json_decode($row->preferences, true, flags: JSON_THROW_ON_ERROR) : [];
        $lockedMethods = StoreSettings::locked($site, 'payments');
        $lockedPresentation = StoreSettings::locked($site, 'stripe_presentation');
        return ['revision' => (int) ($row?->revision ?? 0), 'methods' => $lockedMethods ? config('sellissimo.payments', []) : ($values['methods'] ?? config('sellissimo.payments', [])),
            'inherited' => $lockedMethods || !isset($values['methods']), 'hostingMethods' => config('sellissimo.payments', []),
            'methodsLocked' => $lockedMethods, 'presentationLocked' => $lockedPresentation,
            'presentation' => $lockedPresentation ? config('sellissimo-stripe.presentation.'.$site, 'hosted')
                : ($values['presentation'] ?? config('sellissimo-stripe.presentation.'.$site, 'hosted')),
            'presentationOverride' => $lockedPresentation ? null : ($values['presentation'] ?? null), 'modes' => array_filter($values['modes'] ?? [], fn ($provider) => !$this->locked($provider, $site), ARRAY_FILTER_USE_KEY)];
    }

    public function save(string $site, array $values, int $revision, string $actor): void
    {
        $values = array_intersect_key($values, array_flip(['methods', 'modes', 'presentation']));
        abort_if((StoreSettings::locked($site, 'payments') && isset($values['methods']))
            || (StoreSettings::locked($site, 'stripe_presentation') && isset($values['presentation'])), 403);
        $this->store->db()->transaction(function () use ($site, $values, $revision, $actor) {
            $settings = $this->store->table('payment_preferences');
            $settings->insertOrIgnore(['site' => $site]);
            $row = $settings->where('site', $site)->lockForUpdate()->firstOrFail();
            abort_unless((int) $row->revision === $revision, 409, __('Another editor saved these settings. Reload and review your changes.'));
            $settings->update(['preferences' => json_encode($values, JSON_THROW_ON_ERROR), 'revision' => $revision + 1]);
            $this->log($actor, $site, 'payment methods updated', [
                'before' => $row->preferences ? json_decode($row->preferences, true, flags: JSON_THROW_ON_ERROR) : [],
                'after' => $values, 'revision' => $revision + 1,
            ]);
        }, 3);
    }

    public function deployment(string $provider): array
    {
        $values = [];
        foreach (self::FIELDS[$provider] as $field) {
            $value = config("sellissimo-$provider.$field");
            if (is_string($value) && $value !== '' && trim($value) === $value) {
                $values[$field] = $value;
            }
        }
        return $values;
    }

    public function mode(string $provider, ?array $credentials = null): string
    {
        $credentials ??= $this->deployment($provider);
        $value = $credentials['secret'] ?? $credentials['key'] ?? '';
        return match (true) {
            str_starts_with($value, 'test_'), str_contains(substr($value, 0, 8), '_test_') => 'test',
            str_starts_with($value, 'live_'), str_contains(substr($value, 0, 8), '_live_') => 'live',
            default => 'unknown',
        };
    }

    public function deploymentId(string $provider, bool $persist = true): ?string
    {
        $values = $this->deployment($provider);
        if (!$values) {
            return null;
        }
        // A keyed identifier lets concurrent starters share an immutable snapshot without storing a comparable hash of a credential.
        $id = hash_hmac('sha256', json_encode([$provider, $values], JSON_THROW_ON_ERROR), app('encrypter')->getKey());
        $connections = $this->store->table('payment_connections');
        if ($persist && ! (clone $connections)->where('id', $id)->exists()) {
            $connections->insertOrIgnore(['id' => $id, 'provider' => $provider, 'mode' => $this->mode($provider, $values),
                'credentials' => Crypt::encrypt($values), 'created_at' => now()]);
        }
        return $id;
    }

    public function profile(string $provider, string $mode): ?object
    {
        return $this->store->table('payment_profiles')->where('handle', "$provider.$mode")->first();
    }

    public function locked(string $provider, string $site): bool
    {
        return (bool) config("sellissimo-$provider.lock_credentials") || StoreSettings::locked($site, $provider.'_connection');
    }

    public function activeId(string $provider, string $site, bool $snapshot = false): ?string
    {
        $mode = $this->locked($provider, $site) ? 'deployment' : ($this->preferences($site)['modes'][$provider] ?? 'deployment');
        return $mode === 'deployment' ? ($snapshot ? $this->deploymentId($provider) : null)
            : ($this->profile($provider, $mode)?->connection_id ?? throw new PaymentUnavailable('Set up the selected payment connection.'));
    }

    public function credentials(string $provider, ?string $id, bool $allowReplacement = true): array
    {
        if ($id === null) {
            return $this->deployment($provider);
        }
        $row = $this->store->table('payment_connections')->where('provider', $provider)->where('id', $id)->first();
        throw_unless($row, PaymentUnavailable::class, 'The original payment connection needs attention.');
        $values = Crypt::decrypt($row->credentials);
        // A replacement key that was verified for this same account and mode can service earlier captures after revocation.
        if ($allowReplacement && $row->account_ref) {
            $replacement = $this->store->table('payment_connections')->where('provider', $provider)->where('mode', $row->mode)
                ->where('account_ref', $row->account_ref)->where('check_passed', true)->whereNotNull('checked_at')->orderByDesc('sequence')->first();
            if ($replacement && $replacement->id !== $id) {
                $field = $provider === 'stripe' ? 'secret' : 'key';
                $values[$field] = Crypt::decrypt($replacement->credentials)[$field];
            }
        }
        return $values;
    }

    public function forSite(string $provider, string $site): array
    {
        try {
            return $this->credentials($provider, $this->activeId($provider, $site));
        } catch (PaymentUnavailable) {
            return [];
        }
    }

    public function bind(object $row, string $site): array
    {
        if (!isset(self::FIELDS[$row->gateway])) {
            return [];
        }
        $id = $row->payment_connection_id ?? ($row->payment_start_state === 'ready'
            ? $this->activeId($row->gateway, $site, true) : $this->deploymentId($row->gateway));
        throw_unless($id, PaymentUnavailable::class, 'Configure the original payment provider before retrying.');
        return ['payment_connection_id' => $id, 'payment_mode' => $this->store->table('payment_connections')->where('id', $id)->value('mode')];
    }

    public function replace(string $provider, string $mode, array $values, string $account, int $revision, string $actor): void
    {
        abort_if(config("sellissimo-$provider.lock_credentials"), 403);
        throw_unless(isset(self::FIELDS[$provider]) && in_array($mode, ['test', 'live'], true) && $this->mode($provider, $values) === $mode
            && !array_diff(array_keys($values), self::FIELDS[$provider]) && filled($account), \InvalidArgumentException::class, 'Invalid payment connection.');
        $this->store->db()->transaction(function () use ($provider, $mode, $values, $account, $revision, $actor) {
            $handle = "$provider.$mode";
            $profiles = $this->store->table('payment_profiles');
            $profiles->insertOrIgnore(compact('handle'));
            $row = $profiles->where('handle', $handle)->lockForUpdate()->firstOrFail();
            abort_unless((int) $row->revision === $revision, 409, __('Another editor saved this connection. Reload and review your changes.'));
            $id = (string) Str::uuid();
            $this->store->table('payment_connections')->insert(['id' => $id, 'provider' => $provider, 'mode' => $mode,
                'account_ref' => $account, 'credentials' => Crypt::encrypt($values), 'check_passed' => true, 'checked_at' => now(), 'created_at' => now()]);
            $profiles->update(['connection_id' => $id, 'revision' => $revision + 1]);
            $this->log($actor, 'all', "$handle connection replaced", [
                'previous_connection' => $row->connection_id, 'connection' => $id, 'revision' => $revision + 1,
            ]);
        }, 3);
    }

    /** Return original and rotated signing credentials for this verified account. */
    public function signingConnections(?object $target): iterable
    {
        $query = $this->store->table('payment_connections')->where('provider', 'stripe');
        $id = $target?->payment_connection_id;
        if ($target && $id) {
            $bound = (clone $query)->where('id', $id)->firstOrFail();
            // Hosting can rotate only the signing secret while keeping the same Stripe API key.
            $deployment = $this->deployment('stripe');
            $originalKey = Crypt::decrypt($bound->credentials)['secret'] ?? null;
            if ($originalKey && isset($deployment['secret'], $deployment['webhook_secret']) && hash_equals($originalKey, $deployment['secret'])) {
                yield [$id, $deployment['webhook_secret']];
            }
            $query->where(function ($q) use ($bound) {
                $q->where('id', $bound->id);
                if ($bound->account_ref) {
                    $q->orWhere(fn ($q) => $q->where('account_ref', $bound->account_ref)->where('mode', $bound->mode));
                }
            });
        } elseif ($target) {
            yield [null, $this->deployment('stripe')['webhook_secret'] ?? null];
            return;
        }
        foreach ($query->orderByDesc('created_at')->cursor() as $row) {
            yield [$row->id, Crypt::decrypt($row->credentials)['webhook_secret'] ?? null];
        }
        if (!$target) {
            yield [null, $this->deployment('stripe')['webhook_secret'] ?? null];
        }
    }

    public function sameAccount(string $provider, ?string $left, ?string $right): bool
    {
        if ($left === $right) {
            return true;
        }
        if ($left === null || $right === null) {
            $field = $provider === 'stripe' ? 'secret' : 'key';
            $captured = $this->credentials($provider, $left ?? $right)[$field] ?? null;
            $deployed = $this->deployment($provider)[$field] ?? null;
            return $captured !== null && $deployed !== null && hash_equals($captured, $deployed);
        }
        $rows = $this->store->table('payment_connections')->where('provider', $provider)->whereIn('id', [$left, $right])->get();
        return $rows->count() === 2 && $rows[0]->account_ref && $rows[0]->account_ref === $rows[1]->account_ref && $rows[0]->mode === $rows[1]->mode;
    }

    public function callback(?string $id): void
    {
        if ($id !== null) {
            $this->store->table('payment_connections')->where('id', $id)->update(['callback_at' => now()]);
        }
    }

    private function log(string $actor, string $scope, string $action, array $changes): void
    {
        $this->store->table('payment_settings_log')->insert(compact('actor', 'scope', 'action') + [
            'changes' => json_encode($changes, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }
}
