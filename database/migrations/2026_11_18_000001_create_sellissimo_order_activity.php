<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Sellissimo\Core\Store;

return new class extends Migration
{
    public function up(): void
    {
        app(Store::class)->db()->getSchemaBuilder()->create('sellissimo_order_activity', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('order_id')->constrained('sellissimo_orders')->cascadeOnDelete();
            $table->string('kind', 32);
            $table->text('message');
            $table->string('author')->nullable();
            $table->string('author_id')->nullable();
            $table->uuid('request_id')->nullable();
            $table->timestamp('created_at');
            $table->index(['order_id', 'id']);
            $table->unique(['order_id', 'request_id']);
        });
    }

    public function down(): void
    {
        app(Store::class)->db()->getSchemaBuilder()->dropIfExists('sellissimo_order_activity');
    }
};
