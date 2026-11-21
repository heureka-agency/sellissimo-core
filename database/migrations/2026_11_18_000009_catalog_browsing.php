<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Sellissimo\Core\Store;

return new class extends Migration
{
    public function up(): void
    {
        $schema = app(Store::class)->db()->getSchemaBuilder();
        $schema->create('sellissimo_catalog_pages', function (Blueprint $t) {
            $t->string('site', 100)->primary();
            $t->string('version', 64)->nullable();
            $t->bigInteger('expires')->nullable();
            $t->json('categories')->nullable();
        });
        $prefix = $schema->getConnection()->getTablePrefix();
        $schema->create('sellissimo_catalog_listings', function (Blueprint $t) use ($prefix) {
            $t->string('site', 100);
            $t->string('sku', 100);
            $t->text('words');
            $t->json('categories');
            $t->boolean('featured');
            $t->integer('position');
            $t->primary(['site', 'sku']);
            $t->index(['site', 'featured', 'position'], $prefix.'ss_catalog_position');
        });
    }

    public function down(): void
    {
        $schema = app(Store::class)->db()->getSchemaBuilder();
        $schema->dropIfExists('sellissimo_catalog_listings');
        $schema->dropIfExists('sellissimo_catalog_pages');
    }
};
