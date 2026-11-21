<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Sellissimo\Core\Store;

return new class extends Migration
{
    public function up(): void
    {
        $schema = app(Store::class)->db()->getSchemaBuilder();
        $schema->create('sellissimo_stock', function (Blueprint $t) {
            $t->string('sku', 100)->primary();
            $t->unsignedInteger('available');
        });
        $schema->create('sellissimo_carts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('token_hash', 64);
            $t->string('site');
            $t->char('currency', 3);
            $t->json('items');
            $t->timestamp('created_at');
        });
        $schema->create('sellissimo_orders', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('cart_id')->unique();
            $t->string('site');
            $t->string('customer')->nullable()->index();
            $t->string('email');
            $t->char('currency', 3);
            $t->unsignedBigInteger('total');
            $t->json('receipt');
            $t->string('gateway');
            $t->string('status')->index();
            $t->string('session_id')->nullable();
            $t->text('checkout_url')->nullable();
            $t->string('payment_ref')->nullable();
            $t->string('tracking')->nullable();
            $t->timestamp('emailed_at')->nullable();
            $t->timestamp('created_at');
        });
        $schema->create('sellissimo_refunds', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('order_id')->index();
            $t->string('sku', 100);
            $t->unsignedBigInteger('amount');
            $t->string('status')->index();
        });
    }

    public function down(): void
    {
        foreach (['refunds', 'orders', 'carts', 'stock'] as $name) {
            app(Store::class)->db()->getSchemaBuilder()->dropIfExists('sellissimo_'.$name);
        }
    }
};
