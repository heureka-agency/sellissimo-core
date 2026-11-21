<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Sellissimo\Core\Database\IndexName;
use Sellissimo\Core\Store;

return new class extends Migration {
    public function up(): void
    {
        $connection = app(Store::class)->db();
        $schema = $connection->getSchemaBuilder();
        $definitions = [
            'sellissimo_queue_fields' => ['sellissimo_queue_fields_value', 'ss_queue_lookup', ['handle', 'term']],
            'sellissimo_stock_subscriptions' => ['ss_waiting_variant', 'ss_stock_waiting', ['site', 'sku', 'confirmed_at']],
        ];

        foreach ($definitions as $table => [$legacy, $name, $columns]) {
            $indexes = array_column($schema->getIndexes($table), null, 'name');
            if (! isset($indexes[$legacy]) || $indexes[$legacy]['unique'] || $indexes[$legacy]['columns'] !== $columns) {
                continue;
            }

            $scoped = IndexName::forStore($connection->getTablePrefix(), $name);
            if (! isset($indexes[$scoped])) {
                $schema->table($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $scoped));
            } elseif ($indexes[$scoped]['unique'] || $indexes[$scoped]['columns'] !== $columns) {
                throw new RuntimeException("The index [$scoped] on [$table] has a different definition.");
            }
            // Create the replacement first so that interrupted migrations can safely resume.
            $schema->table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($legacy));
        }
    }

    public function down(): void
    {
        // Older releases use these indexes by their columns and can retain the scoped names.
    }
};
