<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Sellissimo\Core\Store;

return new class extends Migration
{
    public function up(): void
    {
        $store = app(Store::class);
        $schema = $store->db()->getSchemaBuilder();
        $schema->create('sellissimo_order_sequences', function (Blueprint $table) {
            $table->string('name')->primary();
            $table->bigInteger('last_number');
        });
        $store->table('order_sequences')->insert(['name' => 'orders', 'last_number' => 0]);
        $schema->table('sellissimo_orders', function (Blueprint $table) {
            $table->bigInteger('order_number')->nullable()->unique();
            $table->index(['site', 'created_at']);
        });
    }

    public function down(): void
    {
        $schema = app(Store::class)->db()->getSchemaBuilder();
        $schema->table('sellissimo_orders', function (Blueprint $table) {
            $table->dropUnique(['order_number']);
            $table->dropIndex(['site', 'created_at']);
            $table->dropColumn('order_number');
        });
        $schema->dropIfExists('sellissimo_order_sequences');
    }
};
