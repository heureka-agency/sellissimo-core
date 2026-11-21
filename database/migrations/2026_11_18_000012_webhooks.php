<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Sellissimo\Core\Store;

return new class extends Migration
{
    public function up(): void
    {
        $schema = app(Store::class)->db()->getSchemaBuilder();
        $schema->create('sellissimo_webhooks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->text('url');
            $t->text('secret');
            $t->json('topics');
            $t->boolean('enabled')->default(true);
            $t->integer('revision')->default(1);
        });
        $schema->create('sellissimo_deliveries', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('endpoint');
            $t->string('event_key', 100);
            $t->string('topic');
            $t->text('payload');
            $t->string('status', 24);
            $t->integer('attempts')->default(0);
            $t->integer('http_status')->nullable();
            $t->string('error')->nullable();
            $t->uuid('lease')->nullable();
            $t->timestamp('available_at');
            $t->timestamp('created_at');
            $t->unique(['endpoint', 'event_key']);
            $t->index(['status', 'available_at']);
        });
    }

    public function down(): void
    {
        $schema = app(Store::class)->db()->getSchemaBuilder();
        $schema->dropIfExists('sellissimo_deliveries');
        $schema->dropIfExists('sellissimo_webhooks');
    }
};
