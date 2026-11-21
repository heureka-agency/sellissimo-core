<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Sellissimo\Core\Store;

return new class extends Migration {
    public function up(): void
    {
        app(Store::class)->db()->getSchemaBuilder()->table('sellissimo_carts', fn (Blueprint $table) => $table->boolean('currency_review')->default(false));
    }

    public function down(): void
    {
        app(Store::class)->db()->getSchemaBuilder()->table('sellissimo_carts', fn (Blueprint $table) => $table->dropColumn('currency_review'));
    }
};
