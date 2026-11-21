<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Sellissimo\Core\Database\IndexName;
use Sellissimo\Core\Store;

return new class extends Migration {
    public function up(): void
    {
        $schema = app(Store::class)->db()->getSchemaBuilder();
        $prefix = $schema->getConnection()->getTablePrefix();
        $schema->create('sellissimo_site_features', function (Blueprint $t) {
            $t->string('site', 100)->primary();
            $t->json('features');
            $t->json('recipients');
            $t->unsignedInteger('revision')->default(0);
        });
        $schema->create('sellissimo_staff_alerts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->char('delivery_key', 64)->unique();
            $t->string('site', 100);
            $t->string('email');
            $t->string('topic', 40);
            $t->json('details');
            $t->timestamp('created_at');
            $t->timestamp('sent_at')->nullable()->index();
        });
        $schema->create('sellissimo_stock_subscriptions', function (Blueprint $t) use ($prefix) {
            $t->uuid('id')->primary();
            $t->char('identity', 64)->unique();
            $t->string('site', 100);
            $t->string('sku', 100);
            $t->string('email');
            $t->timestamp('requested_at');
            $t->timestamp('confirmation_sent_at')->nullable();
            $t->timestamp('confirmed_at')->nullable();
            $t->timestamp('notified_at')->nullable();
            $t->index(['site', 'sku', 'confirmed_at'], IndexName::forStore($prefix, 'ss_stock_waiting'));
        });
        $schema->create('sellissimo_wishes', function (Blueprint $t) {
            $t->string('customer', 100);
            $t->string('site', 100);
            $t->string('sku', 100);
            $t->timestamp('created_at');
            $t->primary(['customer', 'site', 'sku']);
        });
    }

    public function down(): void
    {
        foreach (['wishes', 'stock_subscriptions', 'staff_alerts', 'site_features'] as $table) {
            app(Store::class)->db()->getSchemaBuilder()->dropIfExists('sellissimo_'.$table);
        }
    }
};
