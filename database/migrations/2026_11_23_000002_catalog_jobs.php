<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Sellissimo\Core\Store;

return new class extends Migration
{
    public function up(): void
    {
        $schema = app(Store::class)->db()->getSchemaBuilder();
        $schema->create('sellissimo_catalog_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('staff')->index();
            $table->string('site');
            $table->string('collection');
            $table->integer('issues');
            $table->integer('rows');
            $table->timestamp('created_at')->index();
            $table->timestamp('completed_at')->nullable();
        });
        $schema->create('sellissimo_catalog_job_groups', function (Blueprint $table) {
            $table->id();
            $table->uuid('job')->index();
            $table->longText('plan');
            $table->boolean('applied')->default(false);
        });
        $schema->create('sellissimo_catalog_job_rows', function (Blueprint $table) {
            $table->id();
            $table->uuid('job')->index();
            $table->text('details');
        });
    }

    public function down(): void
    {
        $schema = app(Store::class)->db()->getSchemaBuilder();
        foreach (['rows', 'groups'] as $table) {
            $schema->dropIfExists('sellissimo_catalog_job_'.$table);
        }
        $schema->dropIfExists('sellissimo_catalog_jobs');
    }
};
