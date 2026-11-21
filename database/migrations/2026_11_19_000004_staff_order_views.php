<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Sellissimo\Core\Store;

return new class extends Migration {
    public function up(): void
    {
        app(Store::class)->db()->getSchemaBuilder()->create('sellissimo_order_views', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('staff', 150);
            $table->string('name', 64);
            $table->json('filters');
            $table->unique(['staff', 'name']);
        });
    }

    public function down(): void
    {
        app(Store::class)->db()->getSchemaBuilder()->dropIfExists('sellissimo_order_views');
    }
};
