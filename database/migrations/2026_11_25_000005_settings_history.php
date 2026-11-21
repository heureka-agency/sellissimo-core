<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Sellissimo\Core\Store;

return new class extends Migration {
    public function up(): void
    {
        $schema = app(Store::class)->db()->getSchemaBuilder();
        if (!$schema->hasTable('sellissimo_settings_history')) {
            $schema->create('sellissimo_settings_history', function (Blueprint $table) {
                $table->id();
                $table->string('scope', 100);
                $table->string('section', 80);
                $table->unsignedInteger('revision');
                $table->string('actor')->nullable();
                $table->text('fields');
                $table->timestamp('created_at');
                $table->index(['scope', 'section']);
            });
        }
    }

    public function down(): void
    {
        app(Store::class)->db()->getSchemaBuilder()->dropIfExists('sellissimo_settings_history');
    }
};
