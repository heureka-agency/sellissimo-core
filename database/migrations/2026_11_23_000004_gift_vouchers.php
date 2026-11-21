<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Sellissimo\Core\Store;

return new class extends Migration {
    public function up(): void
    {
        $schema = app(Store::class)->db()->getSchemaBuilder();
        $schema->create('sellissimo_vouchers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('source', 64)->unique();
            $table->string('site', 100);
            $table->char('currency', 3);
            $table->unsignedBigInteger('face');
            $table->unsignedBigInteger('balance');
            $table->char('code_hash', 64)->unique();
            $table->string('ending', 4);
            $table->text('delivery_code')->nullable();
            $table->string('recipient', 254);
            $table->uuid('purchase_id')->nullable()->index();
            $table->string('line_key', 100)->nullable();
            $table->string('actor')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('created_at');
        });
        $schema->create('sellissimo_voucher_movements', function (Blueprint $table) {
            $table->id();
            $table->uuid('voucher_id')->index();
            $table->string('operation', 120)->unique();
            $table->string('kind', 24);
            $table->bigInteger('amount');
            $table->uuid('order_id')->nullable()->index();
            $table->timestamp('created_at');
        });
        $schema->table('sellissimo_orders', function (Blueprint $table) {
            $table->uuid('voucher_id')->nullable();
            $table->unsignedBigInteger('voucher_amount')->default(0);
            $table->unsignedBigInteger('original_cash_amount')->nullable();
        });
        $schema->table('sellissimo_refunds', function (Blueprint $table) {
            $table->uuid('voucher_id')->nullable();
        });
    }

    public function down(): void
    {
        $schema = app(Store::class)->db()->getSchemaBuilder();
        $schema->table('sellissimo_refunds', fn (Blueprint $table) => $table->dropColumn('voucher_id'));
        $schema->table('sellissimo_orders', fn (Blueprint $table) => $table->dropColumn(['voucher_id', 'voucher_amount', 'original_cash_amount']));
        $schema->dropIfExists('sellissimo_voucher_movements');
        $schema->dropIfExists('sellissimo_vouchers');
    }
};
