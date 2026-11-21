<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Sellissimo\Core\Store;

return new class extends Migration {
    public function up(): void
    {
        $store = app(Store::class);
        $schema = $store->db()->getSchemaBuilder();
        $schema->table('sellissimo_carts', fn (Blueprint $t) => $t->json('line_titles')->nullable());
        $schema->table('sellissimo_return_requests', fn (Blueprint $t) => $t->unsignedBigInteger('refund_entitlement')->nullable());
        $schema->table('sellissimo_refunds', fn (Blueprint $t) => $t->uuid('return_request_id')->nullable()->index());
        $schema->table('sellissimo_catalog_listings', function (Blueprint $t) {
            $t->unsignedBigInteger('price')->default(0);
            $t->boolean('tracked')->default(true);
            $t->json('attributes')->nullable();
        });
        $schema->table('sellissimo_catalog_pages', fn (Blueprint $t) => $t->json('facets')->nullable());
        $store->table('catalog_pages')->update(['version' => null]);
    }

    public function down(): void
    {
        $schema = app(Store::class)->db()->getSchemaBuilder();
        $schema->table('sellissimo_catalog_pages', fn (Blueprint $t) => $t->dropColumn('facets'));
        $schema->table('sellissimo_catalog_listings', fn (Blueprint $t) => $t->dropColumn(['price', 'tracked', 'attributes']));
        $schema->table('sellissimo_return_requests', fn (Blueprint $t) => $t->dropColumn('refund_entitlement'));
        $schema->table('sellissimo_refunds', function (Blueprint $t) {
            $t->dropIndex(['return_request_id']);
            $t->dropColumn('return_request_id');
        });
        $schema->table('sellissimo_carts', fn (Blueprint $t) => $t->dropColumn('line_titles'));
    }
};
