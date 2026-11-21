<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Sellissimo\Core\ExchangeParcels;
use Sellissimo\Core\Store;

return new class extends Migration {
    public function up(): void
    {
        $store = app(Store::class);
        $store->db()->getSchemaBuilder()->create('sellissimo_exchange_parcels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('return_id')->index();
            $table->uuid('order_id')->index();
            $table->string('origin', 40)->nullable();
            $table->unsignedInteger('quantity');
            $table->string('tracking', 200);
            $table->json('merchandise');
            $table->timestamp('dispatched_at')->nullable()->index();
        });
        foreach ($store->table('return_requests')->where('status', 'exchanged')->whereNotNull('replacement')->lazyById(100) as $row) {
            $order = $store->table('orders')->where('id', $row->order_id)->first();
            if ($order) {
                $sent = $store->table('return_notices')->where('return_id', $row->id)->where('action', 'dispatch')->oldest('sequence')->first();
                app(ExchangeParcels::class)->record($order, $row, $row->id, null, $row->quantity, 0, $row->tracking ?? '', $sent?->created_at);
            }
        }
    }

    public function down(): void
    {
        app(Store::class)->db()->getSchemaBuilder()->dropIfExists('sellissimo_exchange_parcels');
    }
};
