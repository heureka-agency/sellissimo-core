<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Sellissimo\Core\Store;

return new class extends Migration {
    public function up(): void
    {
        app(Store::class)->db()->getSchemaBuilder()->table('sellissimo_orders', function (Blueprint $table) {
            $table->unsignedInteger('contact_revision')->default(0);
            $table->boolean('contact_notice_pending')->default(false);
            $table->unsignedInteger('fields_revision')->default(0);
            $table->json('operational_fields')->nullable();
        });
    }

    public function down(): void
    {
        app(Store::class)->db()->getSchemaBuilder()->table('sellissimo_orders', fn (Blueprint $table) =>
            $table->dropColumn(['contact_revision', 'contact_notice_pending', 'fields_revision', 'operational_fields']));
    }
};
