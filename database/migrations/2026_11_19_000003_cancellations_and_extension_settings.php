<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Sellissimo\Core\Store;

return new class extends Migration
{
    public function up(): void
    {
        $schema = app(Store::class)->db()->getSchemaBuilder();
        $schema->table('sellissimo_orders', function (Blueprint $table) {
            $table->text('cancellation_reason')->nullable();
            $table->boolean('cancel_notice_pending')->default(false);
        });
        $schema->create('sellissimo_extension_settings', function (Blueprint $table) {
            $table->string('handle', 100)->primary();
            $table->integer('revision');
            $table->json('settings');
        });
    }

    public function down(): void
    {
        $schema = app(Store::class)->db()->getSchemaBuilder();
        $schema->dropIfExists('sellissimo_extension_settings');
        $schema->table('sellissimo_orders', fn (Blueprint $table) => $table->dropColumn(['cancellation_reason', 'cancel_notice_pending']));
    }
};
