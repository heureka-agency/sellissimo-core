<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Sellissimo\Core\Store;

return new class extends Migration
{
    public function up(): void
    {
        app(Store::class)->db()->getSchemaBuilder()->table('sellissimo_discounts', function (Blueprint $table) {
            $table->boolean('combinable')->default(false);
            $table->json('target_skus')->nullable();
            $table->json('target_products')->nullable();
        });
    }

    public function down(): void
    {
        app(Store::class)->db()->getSchemaBuilder()->table('sellissimo_discounts', fn (Blueprint $table) => $table->dropColumn(['combinable', 'target_skus', 'target_products']));
    }
};
