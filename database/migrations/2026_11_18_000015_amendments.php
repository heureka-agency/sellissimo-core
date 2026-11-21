<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Sellissimo\Core\Store;

return new class extends Migration
{
    public function up(): void
    {
        $schema = app(Store::class)->db()->getSchemaBuilder();
        $schema->create('sellissimo_amendments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('order_id')->index();
            $t->string('status', 32)->index();
            $t->bigInteger('difference');
            $t->json('previous');
            $t->json('proposed');
            $t->string('request_hash', 64);
            $t->string('reason');
            $t->string('author');
            $t->string('gateway')->nullable();
            $t->string('session_id')->nullable()->index();
            $t->text('checkout_url')->nullable();
            $t->string('payment_ref')->nullable();
            $t->timestamp('created_at');
            $t->timestamp('started_at')->nullable();
            $t->timestamp('applied_at')->nullable();
        });
        $schema->table('sellissimo_refunds', function (Blueprint $t) {
            $t->uuid('amendment_id')->nullable()->index();
            $t->uuid('capture_id')->nullable();
            $t->uuid('group_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        $schema = app(Store::class)->db()->getSchemaBuilder();
        $schema->table('sellissimo_refunds', function (Blueprint $t) {
            $t->dropIndex(['amendment_id']);
            $t->dropIndex(['group_id']);
            $t->dropColumn(['amendment_id','capture_id','group_id']);
        });
        $schema->dropIfExists('sellissimo_amendments');
    }
};
