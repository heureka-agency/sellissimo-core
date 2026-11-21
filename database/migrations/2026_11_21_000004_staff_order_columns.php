<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Sellissimo\Core\Store;

return new class extends Migration {
    public function up(): void
    {
        app(Store::class)->db()->getSchemaBuilder()->create('sellissimo_staff_order_columns', function (Blueprint $table) {
            $table->string('staff')->primary();
            $table->string('columns');
        });
    }

    public function down(): void
    {
        app(Store::class)->db()->getSchemaBuilder()->dropIfExists('sellissimo_staff_order_columns');
    }
};
