<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Sellissimo\Core\Store;

return new class extends Migration {
    public function up(): void
    {
        $schema = app(Store::class)->db()->getSchemaBuilder();
        $schema->table('sellissimo_discounts', function (Blueprint $table) {
            $table->string('rule_handle')->nullable();
            $table->json('rule_settings')->nullable();
        });
        $schema->table('sellissimo_refunds', fn (Blueprint $table) => $table->boolean('notice_pending')->default(false));
        $schema->create('sellissimo_return_policies', function (Blueprint $table) {
            $table->string('site')->primary();
            $table->unsignedSmallInteger('days')->nullable();
        });
        $schema->create('sellissimo_claim_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('site');
            $table->string('email');
            $table->json('order_ids');
            $table->timestamp('expires_at')->index();
            $table->string('claimed_by')->nullable();
        });
    }

    public function down(): void
    {
        $schema = app(Store::class)->db()->getSchemaBuilder();
        $schema->dropIfExists('sellissimo_claim_invitations');
        $schema->dropIfExists('sellissimo_return_policies');
        $schema->table('sellissimo_refunds', fn (Blueprint $table) => $table->dropColumn('notice_pending'));
        $schema->table('sellissimo_discounts', fn (Blueprint $table) => $table->dropColumn(['rule_handle', 'rule_settings']));
    }
};
