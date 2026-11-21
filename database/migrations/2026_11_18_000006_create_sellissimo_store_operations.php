<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Sellissimo\Core\Money;
use Sellissimo\Core\Store;

return new class extends Migration
{
    public function up(): void
    {
        $store = app(Store::class);
        $schema = $store->db()->getSchemaBuilder();
        if (! $schema->hasTable('sellissimo_locations')) {
            $schema->create('sellissimo_locations', function (Blueprint $t) {
                $t->string('code', 40)->primary();
                $t->string('name', 100);
            });
        }
        $store->table('locations')->insertOrIgnore(['code' => 'main', 'name' => 'Main location']);
        if (! $schema->hasTable('sellissimo_balances')) {
            $schema->create('sellissimo_balances', function (Blueprint $t) {
                $t->string('sku', 100);
                $t->string('location', 40);
                $t->unsignedInteger('quantity');
                $t->primary(['sku', 'location']);
            });
        }
        if (! $schema->hasTable('sellissimo_transfers')) {
            $schema->create('sellissimo_transfers', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('sku', 100);
                $t->string('source', 40);
                $t->string('destination', 40);
                $t->unsignedInteger('quantity');
            });
        }
        if (! $schema->hasTable('sellissimo_movements')) {
            $schema->create('sellissimo_movements', function (Blueprint $t) {
                $t->id();
                $t->string('sku', 100)->index();
                $t->string('location', 40);
                $t->integer('delta');
                $t->unsignedInteger('balance');
                $t->string('reason', 150);
                $t->string('actor')->nullable();
                $t->uuid('order_id')->nullable()->index();
                $t->uuid('transfer_id')->nullable()->index();
                $t->timestamp('created_at');
            });
            foreach ($store->table('stock')->orderBy('sku')->lazyById(500, 'sku') as $row) {
                $store->table('movements')->insert(['sku' => $row->sku, 'location' => 'main', 'delta' => $row->available,
                    'balance' => $row->available, 'reason' => 'Opening balance', 'created_at' => now()]);
            }
        }
        if (! $schema->hasTable('sellissimo_address_books')) {
            $schema->create('sellissimo_address_books', function (Blueprint $t) {
                $t->char('scope', 64)->primary();
                $t->json('addresses');
                $t->unsignedInteger('revision');
            });
        }
        if (! $schema->hasTable('sellissimo_return_requests')) {
            $schema->create('sellissimo_return_requests', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('order_id')->index();
                $t->string('line_key', 100);
                $t->unsignedInteger('quantity');
                $t->text('reason');
                $t->string('status', 32)->index();
                $t->string('replacement', 100)->nullable();
                $t->string('tracking')->nullable();
                $t->text('staff_message')->nullable();
                $t->timestamps();
            });
        }
        if (! $schema->hasColumn('sellissimo_orders', 'paid_at')) {
            $schema->table('sellissimo_orders', fn (Blueprint $t) => $t->timestamp('paid_at')->nullable()->index());
        }
        if (! $schema->hasColumn('sellissimo_refunds', 'settled_at')) {
            $schema->table('sellissimo_refunds', fn (Blueprint $t) => $t->timestamp('settled_at')->nullable()->index());
        }
        // Only backfill dates supported by the historical payment timeline.
        foreach ($store->table('orders')->whereIn('status', ['paid', 'refunded'])->whereNull('paid_at')->lazyById(200) as $order) {
            $paid = $store->table('order_activity')->where('order_id', $order->id)->where('kind', 'payment')
                ->whereIn('message', ['Payment confirmed.', 'Order completed without payment.'])->min('created_at');
            if ($paid) {
                $store->table('orders')->where('id', $order->id)->update(['paid_at' => $paid]);
            }
            $refunds = $store->table('refunds')->where('order_id', $order->id)->where('status', 'succeeded')->get()->groupBy('amount');
            foreach ($refunds as $amount => $group) {
                $message = 'Refund issued for '.Money::display((int) $amount, $order->currency).'.';
                $dates = $store->table('order_activity')->where('order_id', $order->id)->where('message', $message)->distinct()->pluck('created_at');
                if ($dates->count() === 1 && $store->table('order_activity')->where('order_id', $order->id)->where('message', $message)->count() === $group->count()) {
                    $store->table('refunds')->whereIn('id', $group->pluck('id'))->update(['settled_at' => $dates->first()]);
                }
            }
        }
    }

    public function down(): void
    {
        $schema = app(Store::class)->db()->getSchemaBuilder();
        foreach (['return_requests', 'address_books', 'movements', 'transfers', 'balances', 'locations'] as $table) {
            $schema->dropIfExists('sellissimo_'.$table);
        }
        foreach (['orders' => 'paid_at', 'refunds' => 'settled_at'] as $table => $column) {
            $schema->table('sellissimo_'.$table, function (Blueprint $t) use ($column) {
                $t->dropIndex([$column]);
                $t->dropColumn($column);
            });
        }
    }
};
