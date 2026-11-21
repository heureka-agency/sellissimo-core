<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Sellissimo\Core\Store;

return new class extends Migration {
    public function up(): void
    {
        $store = app(Store::class);
        foreach (['orders' => 'pending', 'amendments' => 'awaiting_payment'] as $table => $pending) {
            $store->db()->getSchemaBuilder()->table('sellissimo_'.$table, fn (Blueprint $blueprint) => $blueprint->string('payment_presentation', 20)->nullable());
            // A pre-upgrade session can exist at Stripe before its ID reaches our database.
            $store->table($table)->where('gateway', 'stripe')->where('status', $pending)->update(['payment_presentation' => 'legacy_hosted']);
        }
    }

    public function down(): void
    {
        foreach (['orders', 'amendments'] as $table) {
            app(Store::class)->db()->getSchemaBuilder()->table('sellissimo_'.$table, fn (Blueprint $blueprint) => $blueprint->dropColumn('payment_presentation'));
        }
    }
};
