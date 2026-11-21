<?php

namespace Sellissimo\Core;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class CartRecovery
{
    public function __construct(private Store $store) {}

    private function shoppingCarts(): Builder
    {
        return $this->store->table('carts')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('sellissimo_drafts')
            ->whereColumn('cart_id', 'sellissimo_carts.id'));
    }

    private function open(): Builder
    {
        return $this->shoppingCarts()->whereNotExists(fn ($q) => $q->selectRaw('1')->from('sellissimo_orders')
            ->whereColumn('cart_id', 'sellissimo_carts.id'));
    }

    public function inactive(): Builder
    {
        return $this->open()->where('active_at', '<=', now()->subMinutes(max(1, \Sellissimo\Core\OperationalSettings::shared('recovery_inactive_minutes', 'recovery.inactive_minutes', 60))))
            ->where('line_count', '>', 0);
    }

    public function recovered(): Builder
    {
        return $this->shoppingCarts()->whereNotNull('recovered_at')->select('sellissimo_carts.*')
            ->selectSub($this->store->table('orders')->select('id')->whereColumn('cart_id', 'sellissimo_carts.id')->limit(1), 'order_id');
    }

    public function recordQuote(object $cart, Request $request): void
    {
        $values = ['active_at' => now()];
        if ($request->has('recovery_consent')) {
            $data = $request->validate(['recovery_consent' => 'required|boolean', 'email' => 'required_if:recovery_consent,1,true|nullable|email|max:254']);
            $email = $request->boolean('recovery_consent') ? mb_strtolower(trim($data['email'])) : null;
            if ($cart->recovery_email !== $email) {
                $values += ['recovery_email' => $email, 'recovery_key' => null, 'reminded_at' => null];
            }
        }
        $this->open()->where('id', $cart->id)->update($values);
    }

    public function remind(string $id): void
    {
        $this->store->db()->transaction(function () use ($id) {
            $cart = $this->inactive()->where('id', $id)->lockForUpdate()->first();
            abort_unless($cart && $cart->recovery_email, 409, 'This checkout is no longer eligible for a reminder.');
            if ($cart->reminded_at) {
                return;
            }
            $key = (string) Str::uuid();
            $url = URL::temporarySignedRoute('sellissimo.recover', now()->addDays(7), ['cart' => $id, 'key' => $key]);
            app(StoreMail::class)->send('recovery', $cart->site, $cart->recovery_email, $url);
            $this->store->table('carts')->where('id', $id)->update(['recovery_key' => $key, 'reminded_at' => now()]);
        });
    }

    public function claim(Request $request, string $id): array
    {
        return $this->store->db()->transaction(function () use ($request, $id) {
            $cart = $this->open()->where('id', $id)->lockForUpdate()->first();
            abort_unless($cart && $cart->recovery_key && hash_equals($cart->recovery_key, (string) $request->query('key')), 410);
            abort_if($cart->customer !== null && $cart->customer !== (string) $request->user()?->getAuthIdentifier(), 403, 'Sign in to the account that owns this cart.');
            if ($request->isMethod('get')) {
                return [$cart, null];
            }
            $token = Str::random(64);
            $this->store->table('carts')->where('id', $id)->update(['token_hash' => hash('sha256', $token), 'active_at' => now(),
                'recovered_at' => now(), 'recovery_key' => null]);

            return [$cart, ['id' => $id, 'token' => $cart->customer ? null : $token]];
        }, 3);
    }

    public function prune(): void
    {
        $cutoff = now()->subDays(max(1, (int) \Sellissimo\Core\OperationalSettings::shared('recovery_retention_days', 'recovery.retention_days', 90)));
        foreach ($this->open()->where('active_at', '<', $cutoff)->lazyById(200) as $row) {
            $this->store->db()->transaction(function () use ($row, $cutoff) {
                $cart = $this->open()->where('id', $row->id)->where('active_at', '<', $cutoff)->lockForUpdate()->first();
                if ($cart) {
                    $this->store->table('account_carts')->where('cart_id', $cart->id)->update(['cart_id' => null]);
                    $this->store->table('carts')->where('id', $cart->id)->delete();
                }
            }, 3);
        }
    }
}
