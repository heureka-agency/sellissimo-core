<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Sellissimo\Core\Store;

return new class extends Migration
{
    public function up(): void
    {
        app(Store::class)->db()->getSchemaBuilder()->table('sellissimo_discounts', function (Blueprint $table) {
            $table->string('site', 100)->nullable();
        });
    }

    public function down(): void
    {
        app(Store::class)->db()->getSchemaBuilder()->table('sellissimo_discounts', fn (Blueprint $table) => $table->dropColumn('site'));
    }
};
