<?php

namespace Sellissimo\Core;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class StockSubscriptions
{
    public function __construct(private Store $store, private Catalog $catalog, private StoreMail $mail) {}

    public function subscribe(string $site, string $sku, string $email): void
    {
        $product = $this->catalog->products($site, false, [$sku])[$sku] ?? null;
        abort_unless($product && $product['available'] === 0, 422, __('sellissimo::features.only_sold_out'));
        $email = mb_strtolower(trim($email));
        $identity = hash('sha256', implode('|', [$site, $sku, $email]));
        $id = $this->store->db()->transaction(function () use ($identity, $site, $sku, $email) {
            $query = $this->store->table('stock_subscriptions')->where('identity', $identity);
            $now = now();
            $this->store->table('stock_subscriptions')->insertOrIgnore(compact('identity', 'site', 'sku', 'email') +
                ['id' => (string) Str::uuid(), 'requested_at' => $now]);
            $row = $query->lockForUpdate()->firstOrFail();
            if ($row->notified_at || (!$row->confirmed_at && Carbon::parse($row->requested_at)->addDays(2)->isPast())) {
                $id = (string) Str::uuid();
                $query->update(['id' => $id, 'requested_at' => $now, 'confirmation_sent_at' => null, 'confirmed_at' => null, 'notified_at' => null]);
                return $id;
            }
            return $row->id;
        }, 3);
        try {
            $this->send($id);
        } catch (\Throwable $error) {
            report($error); // Pending confirmation remains available to the scheduler.
        }
    }

    public function due()
    {
        return $this->store->table('stock_subscriptions')->whereNull('notified_at')->where(function ($query) {
            $query->where(function ($q) {
                $q->whereNull('confirmed_at')->whereNull('confirmation_sent_at')->where('requested_at', '>', now()->subDays(2));
            })->orWhere(function ($q) {
                $q->whereNotNull('confirmed_at')->whereIn('sku', $this->store->table('stock')->select('sku')->where('available', '>', 0));
            });
        });
    }

    public function send(string $id): void
    {
        $this->store->db()->transaction(function () use ($id) {
            $query = $this->store->table('stock_subscriptions')->where('id', $id);
            $row = $query->whereNull('notified_at')->lockForUpdate()->first();
            if (!$row || !StoreFeatures::enabled($row->site, 'back_in_stock')) {
                return;
            }
            $product = $this->catalog->products($row->site, false, [$row->sku])[$row->sku] ?? null;
            if (!$product) {
                return;
            }
            $params = ['subscription' => $id];
            $unsubscribe = URL::signedRoute('sellissimo.stock.unsubscribe', $params);
            $data = ['productTitle' => $product['title'], 'unsubscribe' => $unsubscribe];
            if ($row->confirmed_at) {
                $url = ProductMetadata::storefrontUrl($row->site, $product);
                if (!($product['available'] > 0) || !$url) {
                    return;
                }
                $this->mail->send('stock_available', $row->site, $row->email, $url, $data);
                $query->update(['notified_at' => now()]);
            } elseif (!$row->confirmation_sent_at && ($until = Carbon::parse($row->requested_at)->addDays(2))->isFuture()) {
                $this->mail->send('stock_confirm', $row->site, $row->email, URL::temporarySignedRoute('sellissimo.stock.confirm', $until, $params), $data);
                $query->update(['confirmation_sent_at' => now()]);
            }
        });
    }
}
