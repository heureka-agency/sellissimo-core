<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Sellissimo\Core\Store;

return new class extends Migration
{
    public function up(): void
    {
        app(Store::class)->db()->getSchemaBuilder()->create('sellissimo_historical_imports', function (Blueprint $t) {
            $t->string('source_key', 180)->primary();
            $t->string('checksum', 64);
            $t->string('destination');
            $t->json('source_record');
        });
    }

    public function down(): void
    {
        app(Store::class)->db()->getSchemaBuilder()->dropIfExists('sellissimo_historical_imports');
    }
};
