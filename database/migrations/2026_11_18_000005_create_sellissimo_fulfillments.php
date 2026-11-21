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
        if (! $schema->hasColumn('sellissimo_orders', 'partially_shipped')) {
            $schema->table('sellissimo_orders', fn (Blueprint $t) => $t->boolean('partially_shipped')->default(false));
        }
        if (! $schema->hasTable('sellissimo_parcels')) {
            $schema->create('sellissimo_parcels', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('order_id')->index();
                $t->unsignedInteger('number');
                $t->unique(['order_id', 'number']);
                $t->string('tracking');
                $t->json('items');
                $t->timestamp('created_at')->nullable();
                $t->timestamp('queued_at')->nullable();
                $t->timestamp('emailed_at')->nullable();
            });
        }
        if (! $schema->hasTable('sellissimo_returns')) {
            $schema->create('sellissimo_returns', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('order_id')->index();
                $t->string('line_key', 100);
                $t->unsignedInteger('quantity');
                $t->unsignedInteger('restocked');
                $t->timestamp('created_at');
            });
        }
        foreach ($store->table('orders')->whereNotNull('tracking')->lazyById(200) as $order) {
            $items = [];
            foreach (json_decode($order->receipt, true, flags: JSON_THROW_ON_ERROR)['lines'] as $line) {
                if (! $line['digital']) {
                    $items[$line['key'] ?? $line['sku']] = $line['qty'];
                }
            }
            $store->table('parcels')->insertOrIgnore(['id' => $order->id, 'order_id' => $order->id,
                'number' => 1, 'items' => json_encode($items, JSON_THROW_ON_ERROR), 'tracking' => $order->tracking,
                'queued_at' => $order->delivery_queued_at, 'emailed_at' => $order->delivery_emailed_at]);
        }
    }

    public function down(): void
    {
        $schema = app(Store::class)->db()->getSchemaBuilder();
        $schema->dropIfExists('sellissimo_returns');
        $schema->dropIfExists('sellissimo_parcels');
        $schema->table('sellissimo_orders', fn (Blueprint $t) => $t->dropColumn('partially_shipped'));
    }
};
