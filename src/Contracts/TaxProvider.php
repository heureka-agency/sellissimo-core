<?php

namespace Sellissimo\Core\Contracts;

interface TaxProvider
{
    /** Checkout address fields needed to determine a rate, such as country and postal_code. */
    public function requiredFields(array $address, array $classes, string $site): array;

    /** Map tax class handles to non-negative integer rates in basis points. */
    public function rates(array $address, string $site): array;
}
