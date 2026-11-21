<?php

namespace Sellissimo\Core\Contracts;

interface ShippingProvider
{
    /** Return options keyed by service code. Each option contains label, amount in minor units and an optional boolean pay_on_delivery flag. */
    public function quotes(array $physicalLines, array $address, string $currency): array;
}
