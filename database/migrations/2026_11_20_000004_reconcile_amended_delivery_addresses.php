<?php

use Illuminate\Database\Migrations\Migration;
use Sellissimo\Core\Store;

return new class extends Migration
{
    public function up(): void
    {
        $store = app(Store::class);
        $appliedOrders = $store->table('amendments')->where('status', 'applied')->select('order_id');
        $orders = $store->table('orders')->whereNotNull('delivery_override')->whereIn('id', $appliedOrders);
        foreach ($orders->select('id')->lazyById(100) as $candidate) {
            $store->db()->transaction(function () use ($store, $candidate) {
                $orders = $store->table('orders')->where('id', $candidate->id);
                $order = (clone $orders)->lockForUpdate()->first();
                if (! $order?->delivery_override || ! isset(json_decode($order->receipt, true)['shipping_address'])) {
                    return;
                }

                $activity = $store->table('order_activity')->where('order_id', $candidate->id);
                $correction = (clone $activity)->where('kind', 'address')->max('id');
                $settlement = (clone $activity)->where('kind', 'amendment')->where('message', 'like', 'Order items updated.%')->max('id');
                if ($correction !== null && $settlement > $correction) {
                    $orders->update(['delivery_override' => null]);
                }
            }, 3);
        }
    }

    public function down(): void {}
};
