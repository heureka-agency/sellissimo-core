<?php

namespace Sellissimo\Core;


class ShipmentEmails
{
    public function __construct(private Store $store) {}

    public function send(string $id): void
    {
        $this->store->db()->transaction(function () use ($id) {
            $parcel = $this->store->table('parcels')->where('id', $id)->lockForUpdate()->firstOrFail();
            if (! $parcel->queued_at || $parcel->emailed_at) {
                return;
            }
            $order = $this->store->table('orders')->where('id', $parcel->order_id)->lockForUpdate()->firstOrFail();
            $order->tracking = $parcel->tracking;
            $parcel->items = json_decode($parcel->items, true, flags: JSON_THROW_ON_ERROR);
            $url = Checkout::link($order);
            app(StoreMail::class)->send('shipped', $order->site, $order->email, $url, ['order' => $order, 'parcel' => $parcel, 'receiptUrl' => $url]);
            $this->store->table('parcels')->where('id', $id)->update(['emailed_at' => now()]);
            $latest = $this->store->table('parcels')->where('order_id', $order->id)->max('number');
            if ((int) $latest === (int) $parcel->number) {
                $this->store->table('orders')->where('id', $order->id)
                    ->update(['delivery_queued_at' => $parcel->queued_at, 'delivery_emailed_at' => now()]);
            }
            $this->store->recordActivity($order->id, 'shipment', 'Shipment email sent.');
        });
    }

    public function dispatch(string $id): void
    {
        try {
            $this->send($id);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
