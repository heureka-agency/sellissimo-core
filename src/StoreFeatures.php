<?php

namespace Sellissimo\Core;

use Illuminate\Validation\ValidationException;

class StoreFeatures
{
    public const FLAGS = ['wishlists', 'back_in_stock', 'vouchers', 'cross_sell', 'upsell', 'auto_cross_sell', 'auto_upsell'];

    public const ALERTS = ['order.placed', 'stock.low', 'stock.exhausted'];

    public static function enabled(string $site, string $feature): bool
    {
        return in_array($feature, self::get($site)['features'], true);
    }

    public static function get(string $site): array
    {
        $row = app(Store::class)->table('site_features')->where('site', $site)->first();

        return ['features' => $row ? json_decode($row->features, true) : array_values(array_intersect(self::FLAGS, config('sellissimo.default_features', []))),
            'recipients' => $row ? json_decode($row->recipients, true) : [], 'revision' => $row ? (int) $row->revision : 0];
    }

    public static function save(string $site, array $features, array $recipients, int $revision): void
    {
        $store = app(Store::class);
        $store->db()->transaction(function () use ($store, $site, $features, $recipients, $revision) {
            $store->table('site_features')->insertOrIgnore(['site' => $site, 'features' => '[]', 'recipients' => '{}', 'revision' => 0]);
            $updated = $store->table('site_features')->where(['site' => $site, 'revision' => $revision])->update([
                'features' => json_encode(array_values(array_intersect(self::FLAGS, $features)), JSON_THROW_ON_ERROR),
                'recipients' => json_encode($recipients, JSON_THROW_ON_ERROR), 'revision' => $revision + 1,
            ]);
            if (! $updated) {
                throw ValidationException::withMessages(['revision' => __('The settings changed. Reload before saving.')]);
            }
        }, 3);
    }
}
