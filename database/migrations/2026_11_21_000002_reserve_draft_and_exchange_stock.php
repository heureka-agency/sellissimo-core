<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Sellissimo\Core\StockHolds;
use Sellissimo\Core\Store;

return new class extends Migration
{
    public function up(): void
    {
        $store = app(Store::class);
        $schema = $store->db()->getSchemaBuilder();
        $schema->table('sellissimo_drafts', function (Blueprint $table) {
            $table->timestamp('reserve_until')->nullable()->index();
            $table->json('reserved_stock')->nullable();
        });
        $schema->table('sellissimo_return_requests', function (Blueprint $table) {
            $table->json('reserved_stock')->nullable();
            $table->string('requested_replacement', 100)->nullable();
        });
        $store->table('return_requests')->whereNotNull('replacement')->update(['requested_replacement' => $store->db()->raw('replacement')]);
        $schema->create('sellissimo_return_notices', function (Blueprint $table) {
            $table->bigIncrements('sequence');
            $table->uuid('id')->unique();
            $table->uuid('return_id')->index();
            $table->string('action', 24);
            $table->string('replacement', 100)->nullable();
            $table->string('tracking', 200)->nullable();
            $table->text('message')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('sent_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        $store = app(Store::class);
        foreach (['drafts', 'return_requests'] as $table) {
            foreach ($store->table($table)->whereNotNull('reserved_stock')->lazyById() as $row) {
                $store->db()->transaction(function () use ($table, $row, $store) {
                    app(StockHolds::class)->adjust(json_decode($row->reserved_stock, true), [], 'Reservation released', null, $row->order_id ?? null);
                    $store->table($table)->where('id', $row->id)->update(['reserved_stock' => null]);
                });
            }
        }
        $schema = $store->db()->getSchemaBuilder();
        $schema->dropIfExists('sellissimo_return_notices');
        $schema->table('sellissimo_return_requests', fn (Blueprint $t) => $t->dropColumn(['reserved_stock', 'requested_replacement']));
        $schema->table('sellissimo_drafts', function (Blueprint $t) {
            $t->dropIndex(['reserve_until']);
            $t->dropColumn(['reserve_until', 'reserved_stock']);
        });
    }
};
