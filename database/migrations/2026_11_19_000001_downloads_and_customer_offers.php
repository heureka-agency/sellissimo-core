<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Sellissimo\Core\Store;

return new class extends Migration
{
    public function up(): void
    {
        $schema = app(Store::class)->db()->getSchemaBuilder();
        $schema->create('sellissimo_download_usage', function (Blueprint $t) {
            $t->uuid('order_id');
            $t->string('line_key', 100);
            $t->unsignedBigInteger('downloads');
            $t->primary(['order_id', 'line_key']);
        });
        $schema->table('sellissimo_discounts', fn (Blueprint $t) => $t->json('target_customers')->nullable());
    }

    public function down(): void
    {
        $schema = app(Store::class)->db()->getSchemaBuilder();
        $schema->table('sellissimo_discounts', fn (Blueprint $t) => $t->dropColumn('target_customers'));
        $schema->dropIfExists('sellissimo_download_usage');
    }
};
