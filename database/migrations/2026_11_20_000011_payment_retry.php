<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Sellissimo\Core\Store;

return new class extends Migration {
    public function up(): void
    {
        app(Store::class)->db()->getSchemaBuilder()->table('sellissimo_carts', fn (Blueprint $table) => $table->uuid('retry_order_id')->nullable()->unique());
    }

    public function down(): void
    {
        app(Store::class)->db()->getSchemaBuilder()->table('sellissimo_carts', function (Blueprint $table) {
            $table->dropUnique(['retry_order_id']);
            $table->dropColumn('retry_order_id');
        });
    }
};
