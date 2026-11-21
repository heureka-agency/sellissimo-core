<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Sellissimo\Core\Store;

return new class extends Migration
{
    public function up(): void
    {
        $schema = app(Store::class)->db()->getSchemaBuilder();
        $schema->table('sellissimo_parcels', fn (Blueprint $t) => $t->string('origin', 40)->nullable());
        $schema->table('sellissimo_returns', fn (Blueprint $t) => $t->string('location', 40)->nullable());
        $schema->create('sellissimo_order_reallocations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('order_id');
            $t->string('sku', 100);
            $t->string('source', 40);
            $t->string('destination', 40);
            $t->unsignedInteger('quantity');
        });
        $schema->table('sellissimo_disputes', fn (Blueprint $t) => $t->timestamp('cash_checked_at')->nullable());
        $schema->create('sellissimo_dispute_movements', function (Blueprint $t) {
            $t->string('reference')->primary();
            $t->string('dispute_id')->index();
            $t->uuid('order_id')->index();
            $t->string('currency', 3);
            $t->bigInteger('amount');
            $t->bigInteger('fee')->default(0);
            $t->timestamp('occurred_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        $schema = app(Store::class)->db()->getSchemaBuilder();
        $schema->dropIfExists('sellissimo_dispute_movements');
        $schema->table('sellissimo_disputes', fn (Blueprint $t) => $t->dropColumn('cash_checked_at'));
        $schema->dropIfExists('sellissimo_order_reallocations');
        $schema->table('sellissimo_returns', fn (Blueprint $t) => $t->dropColumn('location'));
        $schema->table('sellissimo_parcels', fn (Blueprint $t) => $t->dropColumn('origin'));
    }
};
