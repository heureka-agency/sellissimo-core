<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Sellissimo\Core\Store;

return new class extends Migration
{
    public function up(): void
    {
        $store = app(Store::class);
        $schema = $store->db()->getSchemaBuilder();
        $schema->create('sellissimo_discounts', function (Blueprint $t) {
            $t->string('code', 80)->primary();
            $t->string('type', 16);
            $t->char('currency', 3);
            $t->unsignedBigInteger('amount');
            $t->unsignedBigInteger('minimum');
            $t->date('starts_on')->nullable();
            $t->date('ends_on')->nullable();
            $t->boolean('enabled');
            $t->unsignedInteger('revision')->default(1);
        });
        $schema->create('sellissimo_tax_classes', function (Blueprint $t) {
            $t->string('handle', 64)->primary();
            $t->string('name');
            $t->boolean('inclusive');
            $t->unsignedInteger('revision')->default(1);
        });
        $store->table('tax_classes')->insert(['handle' => 'standard', 'name' => 'Standard', 'inclusive' => false]);
        $schema->create('sellissimo_tax_zones', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->string('tax_class', 64);
            $t->char('country', 2)->index();
            $t->string('region', 100)->nullable();
            $t->string('postcode', 32)->nullable();
            $t->unsignedSmallInteger('basis_points');
            $t->boolean('enabled');
            $t->unsignedInteger('revision')->default(1);
        });
    }

    public function down(): void
    {
        foreach (['tax_zones', 'tax_classes', 'discounts'] as $table) {
            app(Store::class)->db()->getSchemaBuilder()->dropIfExists('sellissimo_'.$table);
        }
    }
};
