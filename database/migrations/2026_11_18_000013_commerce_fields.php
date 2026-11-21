<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Sellissimo\Core\Store;

return new class extends Migration
{
    public function up(): void
    {
        $schema = app(Store::class)->db()->getSchemaBuilder();
        $schema->table('sellissimo_carts', function (Blueprint $t) {
            $t->uuid('field_scope')->nullable();
            $t->json('order_fields')->nullable();
        });
        $schema->create('sellissimo_field_uploads', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('scope')->index();
            $t->string('field', 150);
            $t->string('name');
            $t->string('path');
            $t->string('disk');
            $t->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        $schema = app(Store::class)->db()->getSchemaBuilder();
        $schema->dropIfExists('sellissimo_field_uploads');
        $schema->table('sellissimo_carts', fn (Blueprint $t) => $t->dropColumn(['field_scope', 'order_fields']));
    }
};
