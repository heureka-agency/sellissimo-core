<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Sellissimo\Core\Store;

return new class extends Migration
{
    public function up(): void
    {
        $store = app(Store::class);
        $store->db()->getSchemaBuilder()->table('sellissimo_carts', function (Blueprint $t) {
            $t->timestamp('active_at')->nullable()->index();
            $t->unsignedInteger('line_count')->default(0);
            $t->string('recovery_email')->nullable();
            $t->uuid('recovery_key')->nullable();
            $t->timestamp('reminded_at')->nullable();
            $t->timestamp('recovered_at')->nullable();
        });
        foreach ($store->table('carts')->select('id', 'items')->lazyById(250) as $cart) {
            $store->table('carts')->where('id', $cart->id)->update(['line_count' => count(json_decode($cart->items, true, flags: JSON_THROW_ON_ERROR))]);
        }
        $store->table('carts')->update(['active_at' => $store->db()->raw('created_at')]);
    }

    public function down(): void
    {
        app(Store::class)->db()->getSchemaBuilder()->table('sellissimo_carts', function (Blueprint $t) {
            $t->dropIndex(['active_at']);
            $t->dropColumn(['active_at', 'line_count', 'recovery_email', 'recovery_key', 'reminded_at', 'recovered_at']);
        });
    }
};
