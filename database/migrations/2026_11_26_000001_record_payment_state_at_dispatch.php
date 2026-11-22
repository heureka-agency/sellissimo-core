<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Sellissimo\Core\Store;

return new class extends Migration {
    public function up(): void
    {
        $schema = app(Store::class)->db()->getSchemaBuilder();
        if (!$schema->hasColumn('sellissimo_parcels', 'paid_at_dispatch')) {
            $schema->table('sellissimo_parcels', fn (Blueprint $table) => $table->boolean('paid_at_dispatch')->nullable());
        }
        if (!$schema->hasColumn('sellissimo_refunds', 'reversed_at')) {
            $schema->table('sellissimo_refunds', fn (Blueprint $table) => $table->timestamp('reversed_at')->nullable()->index());
        }
    }

    public function down(): void
    {
        $schema = app(Store::class)->db()->getSchemaBuilder();
        $schema->table('sellissimo_refunds', fn (Blueprint $table) => $table->dropIndex(['reversed_at']));
        $schema->table('sellissimo_refunds', fn (Blueprint $table) => $table->dropColumn('reversed_at'));
        $schema->table('sellissimo_parcels', fn (Blueprint $table) => $table->dropColumn('paid_at_dispatch'));
    }
};
