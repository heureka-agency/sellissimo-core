<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Sellissimo\Core\Store;

return new class extends Migration {
    public function up(): void
    {
        $schema = app(Store::class)->db()->getSchemaBuilder();
        foreach (['sellissimo_parcels', 'sellissimo_labels'] as $table) {
            $schema->table($table, fn (Blueprint $blueprint) => $blueprint->text('tracking_url')->nullable());
        }
    }

    public function down(): void
    {
        $schema = app(Store::class)->db()->getSchemaBuilder();
        foreach (['sellissimo_parcels', 'sellissimo_labels'] as $table) {
            $schema->table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn('tracking_url'));
        }
    }
};
