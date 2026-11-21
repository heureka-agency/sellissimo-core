<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Sellissimo\Core\LineOptions;
use Sellissimo\Core\Refunds;
use Sellissimo\Core\Store;

return new class extends Migration {
    public function up(): void
    {
        $store = app(Store::class);
        $schema = $store->db()->getSchemaBuilder();
        $schema->table('sellissimo_orders', function (Blueprint $t) {
            $t->boolean('acceptance_notice_pending')->default(false);
            $t->index(['gateway', 'payment_ref']);
        });
        $schema->table('sellissimo_amendments', fn (Blueprint $t) => $t->index(['gateway', 'payment_ref']));
        $schema->table('sellissimo_refunds', function (Blueprint $t) {
            $t->string('provider_ref')->nullable()->unique();
            $t->boolean('external')->default(false);
        });
        $schema->create('sellissimo_disputes', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->uuid('order_id')->index();
            $t->uuid('capture_id')->nullable();
            $t->bigInteger('amount');
            $t->string('currency', 3);
            $t->string('status');
            $t->string('reason')->nullable();
            $t->timestamp('respond_by')->nullable();
        });
        $schema->table('sellissimo_return_requests', fn (Blueprint $t) => $t->bigInteger('refund_line_total')->nullable());
        foreach ($store->table('return_requests')->where('status', 'received')->whereNull('replacement')->lazyById(100) as $row) {
            $order = $store->table('orders')->where('id', $row->order_id)->firstOrFail();
            $line = LineOptions::find(json_decode($order->receipt, true)['lines'], $row->line_key);
            $store->table('return_requests')->where('id', $row->id)->update([
                'refund_line_total' => $line['total'] ?? 0,
                'refund_entitlement' => $row->refund_entitlement ?? app(Refunds::class)->returnEntitlement($order, $row),
            ]);
        }
    }

    public function down(): void
    {
        $schema = app(Store::class)->db()->getSchemaBuilder();
        $schema->table('sellissimo_return_requests', fn (Blueprint $t) => $t->dropColumn('refund_line_total'));
        $schema->dropIfExists('sellissimo_disputes');
        $schema->table('sellissimo_refunds', function (Blueprint $t) {
            $t->dropUnique(['provider_ref']);
            $t->dropColumn(['provider_ref', 'external']);
        });
        $schema->table('sellissimo_amendments', fn (Blueprint $t) => $t->dropIndex(['gateway', 'payment_ref']));
        $schema->table('sellissimo_orders', function (Blueprint $t) {
            $t->dropIndex(['gateway', 'payment_ref']);
            $t->dropColumn('acceptance_notice_pending');
        });
    }
};
