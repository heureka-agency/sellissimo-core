<?php

namespace Sellissimo\Core\Testing;

use Sellissimo\Core\Contracts\PaymentGateway;

/** Supply a fresh pending fixture order and a test-mode or simulated gateway. */
trait PaymentGatewayContract
{
    abstract protected function gateway(): PaymentGateway;

    abstract protected function pendingOrder(): object;

    public function test_starting_the_same_order_reuses_the_payment_session(): void
    {
        $order = $this->pendingOrder();
        $first = $this->gateway()->start($order);
        $second = $this->gateway()->start($order);
        self::assertNotEmpty($first['id']);
        self::assertSame($first['id'], $second['id']);
        self::assertNotEmpty($first['url']);
    }
}
