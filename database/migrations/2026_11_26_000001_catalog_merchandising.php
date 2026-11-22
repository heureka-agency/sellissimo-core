<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Sellissimo\Core\Store;

return new class extends Migration
{
    public function up(): void
    {
        $store = app(Store::class);
        $store->db()->getSchemaBuilder()->table('sellissimo_catalog_listings', function (Blueprint $table) {
            $table->text('upsell_group')->nullable();
            $table->text('product_type')->nullable();
        });
        $store->table('catalog_pages')->update(['version' => null]);
        $defaults = array_intersect(['cross_sell', 'upsell', 'auto_cross_sell', 'auto_upsell'], config('sellissimo.default_features', []));
        if ($defaults) {
            foreach ($store->table('site_features')->get(['site', 'features']) as $row) {
                $features = array_unique([...json_decode($row->features, true, flags: JSON_THROW_ON_ERROR), ...$defaults]);
                $store->table('site_features')->where('site', $row->site)->update(['features' => json_encode(array_values($features), JSON_THROW_ON_ERROR)]);
            }
        }
    }

    public function down(): void
    {
        $store = app(Store::class);
        $store->db()->getSchemaBuilder()->table('sellissimo_catalog_listings', fn (Blueprint $table) => $table->dropColumn(['upsell_group', 'product_type']));
        $store->table('catalog_pages')->update(['version' => null]);
    }
};
