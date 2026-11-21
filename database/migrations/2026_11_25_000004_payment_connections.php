<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Sellissimo\Core\PaymentSettings;
use Sellissimo\Core\Database\IndexName;
use Sellissimo\Core\Store;

return new class extends Migration {
    public function up(): void
    {
        $store = app(Store::class);
        $schema = $store->db()->getSchemaBuilder();
        if (!$schema->hasTable('sellissimo_payment_connections')) {
            $schema->create('sellissimo_payment_connections', function (Blueprint $t) use ($store) {
                $t->bigIncrements('sequence');
                $t->string('id', 64)->unique();
                $t->string('provider', 32);
                $t->string('mode', 16);
                $t->text('credentials');
                $t->string('account_ref')->nullable();
                $t->timestamp('checked_at')->nullable();
                $t->boolean('check_passed')->nullable();
                $t->timestamp('callback_at')->nullable();
                $t->timestamp('created_at');
                $t->index(['provider', 'mode', 'account_ref'], IndexName::forStore($store->db()->getTablePrefix(), 'ss_payment_account'));
            });
        }
        if (!$schema->hasTable('sellissimo_payment_profiles')) {
            $schema->create('sellissimo_payment_profiles', function (Blueprint $t) {
                $t->string('handle', 64)->primary();
                $t->string('connection_id', 64)->nullable();
                $t->unsignedInteger('revision')->default(0);
            });
        }
        if (!$schema->hasTable('sellissimo_payment_preferences')) {
            $schema->create('sellissimo_payment_preferences', function (Blueprint $t) {
                $t->string('site', 100)->primary();
                $t->text('preferences')->nullable();
                $t->unsignedInteger('revision')->default(0);
            });
        }
        if (!$schema->hasTable('sellissimo_payment_settings_log')) {
            $schema->create('sellissimo_payment_settings_log', function (Blueprint $t) {
                $t->id();
                $t->string('actor');
                $t->string('scope');
                $t->string('action');
                $t->timestamp('created_at');
            });
        }
        if (!$schema->hasColumn('sellissimo_payment_settings_log', 'changes')) {
            $schema->table('sellissimo_payment_settings_log', fn (Blueprint $table) => $table->text('changes')->nullable());
        }
        foreach (['orders', 'amendments'] as $name) {
            if (!$schema->hasColumn('sellissimo_'.$name, 'payment_connection_id')) {
                $schema->table('sellissimo_'.$name, function (Blueprint $t) {
                    $t->string('payment_connection_id', 64)->nullable()->index();
                    $t->string('payment_mode', 16)->nullable();
                });
            }
            foreach (['stripe', 'mollie'] as $provider) {
                $query = $store->table($name)->where('gateway', $provider)->whereNull('payment_connection_id');
                if ($query->exists() && ($id = app(PaymentSettings::class)->deploymentId($provider))) {
                    $query->update(['payment_connection_id' => $id, 'payment_mode' => app(PaymentSettings::class)->mode($provider)]);
                }
            }
        }
    }

    public function down(): void
    {
        $schema = app(Store::class)->db()->getSchemaBuilder();
        foreach (['orders', 'amendments'] as $name) {
            $schema->table('sellissimo_'.$name, function (Blueprint $t) {
                $t->dropIndex(['payment_connection_id']);
                $t->dropColumn(['payment_connection_id', 'payment_mode']);
            });
        }
        foreach (['payment_settings_log', 'payment_preferences', 'payment_profiles', 'payment_connections'] as $name) {
            $schema->dropIfExists('sellissimo_'.$name);
        }
    }
};
