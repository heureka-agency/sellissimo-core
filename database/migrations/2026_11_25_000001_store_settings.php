<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Sellissimo\Core\Store;
use Sellissimo\Core\StoreSettings;

return new class extends Migration {
    public function up(): void
    {
        $schema = app(Store::class)->db()->getSchemaBuilder();
        if (!$schema->hasTable('sellissimo_settings')) {
            $schema->create('sellissimo_settings', function (Blueprint $table) {
                $table->string('scope', 100);
                $table->string('section', 80);
                $table->text('settings');
                $table->unsignedInteger('revision');
                $table->primary(['scope', 'section']);
            });
        }
        if (!$schema->hasTable('sellissimo_settings_checks')) {
            $schema->create('sellissimo_settings_checks', function (Blueprint $table) {
                $table->string('scope', 100);
                $table->string('name', 80);
                $table->string('result', 80);
                $table->timestamp('checked_at');
                $table->primary(['scope', 'name']);
            });
        }
        app()->forgetInstance(StoreSettings::class);
    }

    public function down(): void
    {
        $schema = app(Store::class)->db()->getSchemaBuilder();
        $schema->dropIfExists('sellissimo_settings_checks');
        $schema->dropIfExists('sellissimo_settings');
        app()->forgetInstance(StoreSettings::class);
    }
};
