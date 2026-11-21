<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Sellissimo\Core\Store;

return new class extends Migration
{
    public function up(): void
    {
        app(Store::class)->db()->getSchemaBuilder()->create('sellissimo_download_blocks', function (Blueprint $table) {
            $table->uuid('order_id');
            $table->string('sku', 100);
            $table->primary(['order_id', 'sku']);
        });
    }

    public function down(): void
    {
        app(Store::class)->db()->getSchemaBuilder()->dropIfExists('sellissimo_download_blocks');
    }
};
