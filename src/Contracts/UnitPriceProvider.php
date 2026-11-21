<?php

namespace Sellissimo\Core\Contracts;

interface UnitPriceProvider
{
    /** Return the unit amount in minor currency units, or null to keep the current amount. */
    public function unitPrice(int $amount, array $product, array $line, array $cart): ?int;
}
