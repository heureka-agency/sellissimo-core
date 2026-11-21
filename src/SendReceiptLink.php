<?php

namespace Sellissimo\Core;

use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache;

class SendReceiptLink
{
    use Dispatchable;

    public function __construct(private string $site, private string $email, private string $number) {}

    public function handle(Store $store): void
    {
        $order = OrderNumber::findForBuyer($store, $this->site, $this->email, $this->number);
        if ($order && Cache::add('sellissimo.receipt-recovery.'.$order->id, true, now()->addMinutes(10))) {
            try {
                app(StoreMail::class)->send('receipt_link', $order->site, $order->email, Checkout::link($order), ['order' => $order]);
            } catch (\Throwable $e) {
                Cache::forget('sellissimo.receipt-recovery.'.$order->id);
                report($e);
            }
        }
    }
}
