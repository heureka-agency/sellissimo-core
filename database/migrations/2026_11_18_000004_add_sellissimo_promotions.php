<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Sellissimo\Core\Store;

return new class extends Migration
{
    public function up(): void
    {
        $store = app(Store::class);
        $schema = $store->db()->getSchemaBuilder();
        $prefix = $store->db()->getTablePrefix();
        if (! $schema->hasColumn('sellissimo_discounts', 'automatic')) {
            $schema->table('sellissimo_discounts', function (Blueprint $t) use ($prefix) {
                $t->boolean('automatic')->default(false);
                $t->string('name', 100)->nullable();
                $t->unsignedInteger('priority')->default(10);
                $t->unsignedInteger('usage_limit')->nullable();
                $t->unsignedInteger('per_customer_limit')->nullable();
                $t->index(['automatic', 'currency', 'enabled'], $prefix.'ss_automatic_offers');
            });
        }
        if (! $schema->hasTable('sellissimo_coupon_locks')) {
            $schema->create('sellissimo_coupon_locks', fn (Blueprint $t) => $t->string('code', 80)->primary());
        }
        if (! $schema->hasColumn('sellissimo_orders', 'coupon_code')) {
            $schema->table('sellissimo_orders', function (Blueprint $t) use ($prefix) {
                $t->string('coupon_code', 80)->nullable();
                $t->char('coupon_email_hash', 64)->nullable();
                $t->char('coupon_account_email_hash', 64)->nullable();
                $t->index(['coupon_code', 'status'], $prefix.'ss_coupon_status');
                $t->index(['coupon_code', 'customer'], $prefix.'ss_coupon_customer');
                $t->index(['coupon_code', 'coupon_account_email_hash'], $prefix.'ss_coupon_account_email');
                $t->index(['coupon_code', 'coupon_email_hash'], $prefix.'ss_coupon_email');
            });
        }
        // Preserve uses from earlier releases when a merchant adds a limit to an existing code.
        $store->table('orders')->whereNull('coupon_code')->whereNotNull('receipt->code')->where('receipt->code', '!=', '')->select('id', 'receipt', 'email')->chunkById(250, function ($orders) use ($store) {
            foreach ($orders as $order) {
                $code = json_decode($order->receipt, true, flags: JSON_THROW_ON_ERROR)['code'] ?? '';
                if ($code !== '') {
                    $store->table('orders')->where('id', $order->id)->update([
                        'coupon_code' => strtoupper($code), 'coupon_email_hash' => hash('sha256', mb_strtolower(trim($order->email))),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        $connection = app(Store::class)->db();
        $schema = $connection->getSchemaBuilder();
        $prefix = $connection->getTablePrefix();
        $schema->table('sellissimo_orders', function (Blueprint $t) use ($prefix) {
            foreach (['ss_coupon_status', 'ss_coupon_customer', 'ss_coupon_email', 'ss_coupon_account_email'] as $index) {
                $t->dropIndex($prefix.$index);
            }
            $t->dropColumn(['coupon_code', 'coupon_email_hash', 'coupon_account_email_hash']);
        });
        $schema->dropIfExists('sellissimo_coupon_locks');
        $schema->table('sellissimo_discounts', function (Blueprint $t) use ($prefix) {
            $t->dropIndex($prefix.'ss_automatic_offers');
            $t->dropColumn(['automatic', 'name', 'priority', 'usage_limit', 'per_customer_limit']);
        });
    }
};
