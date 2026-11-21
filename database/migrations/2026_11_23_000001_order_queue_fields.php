<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Sellissimo\Core\Database\IndexName;
use Sellissimo\Core\OrderQueueFields;
use Sellissimo\Core\Store;

return new class extends Migration
{
    public function up(): void
    {
        $store = app(Store::class);
        $prefix = $store->db()->getTablePrefix();
        $store->db()->getSchemaBuilder()->create('sellissimo_queue_fields', function (Blueprint $table) use ($prefix) {
            $table->uuid('order_id');
            $table->string('handle', 150);
            $table->char('term', 64);
            $table->primary(['order_id', 'handle']);
            $table->index(['handle', 'term'], IndexName::forStore($prefix, 'ss_queue_lookup'));
        });
        foreach ($store->table('orders')->select('id')->lazyById(200) as $order) {
            app(OrderQueueFields::class)->refresh($order->id);
        }
    }

    public function down(): void
    {
        app(Store::class)->db()->getSchemaBuilder()->dropIfExists('sellissimo_queue_fields');
    }
};
