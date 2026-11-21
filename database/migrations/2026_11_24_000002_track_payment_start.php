<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Sellissimo\Core\Store;

return new class extends Migration {
    public function up(): void
    {
        $schema = app(Store::class)->db()->getSchemaBuilder();
        foreach (['sellissimo_orders', 'sellissimo_amendments'] as $table) {
            if (!$schema->hasColumn($table, 'payment_start_state')) {
                // Older writers may have sent a provider request without saving its response.
                $schema->table($table, fn (Blueprint $blueprint) => $blueprint->string('payment_start_state', 12)->default('uncertain'));
            }
        }
    }

    public function down(): void
    {
        $schema = app(Store::class)->db()->getSchemaBuilder();
        foreach (['sellissimo_orders', 'sellissimo_amendments'] as $table) {
            if ($schema->hasColumn($table, 'payment_start_state')) {
                $schema->table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn('payment_start_state'));
            }
        }
    }
};
