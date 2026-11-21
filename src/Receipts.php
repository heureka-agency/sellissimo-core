<?php

namespace Sellissimo\Core;


class Receipts
{
    public function __construct(private Store $store) {}

    public function sendAcceptance(string $id): void
    {
        $this->store->db()->transaction(function () use ($id) {
            $order = $this->store->table('orders')->where('id', $id)->lockForUpdate()->firstOrFail();
            if (!$order->acceptance_notice_pending) {
                return;
            }
            if ($order->status === 'awaiting_collection') {
                app(StoreMail::class)->send('accepted', $order->site, $order->email, Checkout::link($order), [
                    'order' => $order, 'dueOnDelivery' => Money::display(GiftVouchers::cashPayment($order)->total, $order->currency),
                ]);
            }
            $this->store->table('orders')->where('id', $id)->update(['acceptance_notice_pending' => false]);
        });
    }

    public function send(string $id): void
    {
        $this->store->db()->transaction(function () use ($id) {
            $order = $this->store->table('orders')->where('id', $id)->lockForUpdate()->firstOrFail();
            if ($order->emailed_at || $order->status !== 'paid') {
                return;
            }
            app(StoreMail::class)->send('receipt', $order->site, $order->email, Checkout::link($order), ['order' => $order]);
            $this->store->table('orders')->where('id', $id)->update(['emailed_at' => now()]);
        });
    }
}
