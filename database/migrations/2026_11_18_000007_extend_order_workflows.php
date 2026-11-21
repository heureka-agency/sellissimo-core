<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Sellissimo\Core\Store;

return new class extends Migration
{
    public function up(): void
    {
        $schema = app(Store::class)->db()->getSchemaBuilder();
        $schema->table('sellissimo_drafts', function (Blueprint $t) {
            // Existing links were already issued before this upgrade.
            $t->boolean('payable')->default(true);
            $t->timestamp('link_sent_at')->nullable();
        });
        $schema->table('sellissimo_refunds', function (Blueprint $t) {
            $t->boolean('manual')->default(false);
            $t->string('reason')->nullable();
            $t->string('reference')->nullable();
            $t->string('recorded_by')->nullable();
        });
        $schema->table('sellissimo_orders', function (Blueprint $t) {
            $t->json('delivery_override')->nullable();
        });
        $schema->create('sellissimo_catalog_skus', function (Blueprint $t) {
            $t->string('site', 100);
            $t->string('sku', 100);
            $t->string('entry_id')->index();
            $t->index(['site', 'sku']);
        });
        $schema->create('sellissimo_tax_preferences', function (Blueprint $t) {
            $t->string('site', 100)->primary();
            $t->string('physical_address', 10);
            $t->string('shipping_class', 64)->nullable();
        });
        $schema->create('sellissimo_labels', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('order_id')->index();
            $t->string('provider', 80);
            $t->string('status', 20);
            $t->json('items');
            $t->string('origin');
            $t->string('reference')->nullable();
            $t->string('tracking')->nullable();
            $t->text('label_url')->nullable();
            $t->string('tracking_status')->nullable();
            $t->timestamp('tracking_updated_at')->nullable();
        });
    }

    public function down(): void
    {
        $schema = app(Store::class)->db()->getSchemaBuilder();
        $schema->dropIfExists('sellissimo_labels');
        $schema->dropIfExists('sellissimo_tax_preferences');
        $schema->dropIfExists('sellissimo_catalog_skus');
        $schema->table('sellissimo_orders', fn (Blueprint $t) => $t->dropColumn('delivery_override'));
        $schema->table('sellissimo_refunds', fn (Blueprint $t) => $t->dropColumn(['manual', 'reason', 'reference', 'recorded_by']));
        $schema->table('sellissimo_drafts', fn (Blueprint $t) => $t->dropColumn(['payable', 'link_sent_at']));
    }
};
