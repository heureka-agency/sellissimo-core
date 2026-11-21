<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Sellissimo\Core\Store;

return new class extends Migration
{
    public function up(): void
    {
        $schema = app(Store::class)->db()->getSchemaBuilder();
        $schema->table('sellissimo_carts', fn (Blueprint $t) => $t->string('customer')->nullable());
        $schema->create('sellissimo_account_carts', function (Blueprint $t) {
            $t->char('scope', 64)->primary();
            $t->uuid('cart_id')->nullable();
        });
        $schema->table('sellissimo_orders', function (Blueprint $t) {
            $t->timestamp('delivery_queued_at')->nullable();
            $t->timestamp('delivery_emailed_at')->nullable();
        });
        $schema->create('sellissimo_drafts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('cart_id')->unique();
            $t->json('input');
            $t->unsignedInteger('revision')->default(1);
            $t->timestamp('created_at');
            $t->timestamp('cancelled_at')->nullable();
        });
    }

    public function down(): void
    {
        $schema = app(Store::class)->db()->getSchemaBuilder();
        $schema->dropIfExists('sellissimo_drafts');
        $schema->table('sellissimo_orders', fn (Blueprint $t) => $t->dropColumn(['delivery_queued_at', 'delivery_emailed_at']));
        $schema->dropIfExists('sellissimo_account_carts');
        $schema->table('sellissimo_carts', fn (Blueprint $t) => $t->dropColumn('customer'));
    }
};
