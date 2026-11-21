<?php

namespace Sellissimo\Core\Contracts;

interface DiscountRule
{
    /** Return a merchandise discount in minor units for the configured coupon. */
    public function amount(array $lines, string $currency, array $settings): int;
}
