<?php

namespace Sellissimo\Core\Contracts;

interface ReconcilesOrderPayments
{
    /** Read this provider's original and additional captures and update the local payment history. */
    public function reconcileOrder(string $orderId): void;
}
