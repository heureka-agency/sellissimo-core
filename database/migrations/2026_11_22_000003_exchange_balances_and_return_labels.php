<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Sellissimo\Core\Store;

return new class extends Migration {
    public function up(): void
    {
        $schema = app(Store::class)->db()->getSchemaBuilder();
        $schema->table('sellissimo_return_requests', function (Blueprint $table) {
            $table->json('exchange_quote')->nullable();
            $table->uuid('exchange_amendment')->nullable();
            $table->string('inbound_label_path')->nullable();
            $table->string('inbound_label_disk')->nullable();
            $table->text('inbound_label_url')->nullable();
            $table->string('inbound_tracking', 200)->nullable();
            $table->text('inbound_tracking_url')->nullable();
        });
        $schema->table('sellissimo_return_notices', fn (Blueprint $table) => $table->string('shipping_digest',64)->nullable());
        $schema->table('sellissimo_amendments', fn (Blueprint $table) => $table->uuid('exchange_return')->nullable()->index());
    }

    public function down(): void
    {
        $schema = app(Store::class)->db()->getSchemaBuilder();
        $schema->table('sellissimo_return_notices', fn (Blueprint $table) => $table->dropColumn('shipping_digest'));
        $schema->table('sellissimo_amendments', function (Blueprint $table) {
            $table->dropIndex(['exchange_return']);
            $table->dropColumn('exchange_return');
        });
        $schema->table('sellissimo_return_requests', fn (Blueprint $table) => $table->dropColumn([
            'exchange_quote', 'exchange_amendment', 'inbound_label_path', 'inbound_label_disk', 'inbound_label_url', 'inbound_tracking', 'inbound_tracking_url',
        ]));
    }
};
