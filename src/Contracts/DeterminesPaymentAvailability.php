<?php

namespace Sellissimo\Core\Contracts;

interface DeterminesPaymentAvailability
{
    /** The quoted lines, total, currency, addresses and selected shipping service. */
    public function isAvailable(array $quote, string $site): bool;
}
