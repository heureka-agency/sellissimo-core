<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Sellissimo\Core\Store;

return new class extends Migration {
    public function up(): void
    {
        $store = app(Store::class);
        $schema = $store->db()->getSchemaBuilder();
        $schema->table('sellissimo_parcels', function (Blueprint $table) {
            $table->timestamp('delivered_at')->nullable();
        });
        $schema->table('sellissimo_catalog_listings', function (Blueprint $table) {
            $table->string('entry_id')->nullable();
            $table->index(['site', 'entry_id']);
        });
        $schema->table('sellissimo_labels', fn (Blueprint $table) => $table->timestamp('delivered_at')->nullable());
        $store->table('catalog_pages')->update(['version' => null]);
    }

    public function down(): void
    {
        $schema = app(Store::class)->db()->getSchemaBuilder();
        $schema->table('sellissimo_catalog_listings', function (Blueprint $table) {
            $table->dropIndex(['site', 'entry_id']);
            $table->dropColumn('entry_id');
        });
        $schema->table('sellissimo_labels', fn (Blueprint $table) => $table->dropColumn('delivered_at'));
        $schema->table('sellissimo_parcels', fn (Blueprint $table) => $table->dropColumn('delivered_at'));
    }
};
