<?php

namespace Sellissimo\Core;

use LogicException;
use Sellissimo\Core\Contracts\PaymentGateway;

class PayOnDelivery implements PaymentGateway
{
    public function start(object $order): array
    {
        return ['id' => $order->id, 'url' => Checkout::link($order)];
    }

    public function outcome(object $order): array
    {
        return ['status' => 'pending'];
    }

    public function supportsRefunds(): bool
    {
        return false;
    }

    public function refund(object $order, object $refund): string
    {
        throw new LogicException('Refund a collected payment through the original offline payment method.');
    }
}
