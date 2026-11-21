<?php

namespace Sellissimo\Core\Contracts;

interface CheckoutCountryProvider
{
    /** Return permitted ISO 3166-1 alpha-2 codes for billing or shipping, or null for all countries. */
    public function countries(string $site, string $purpose): ?array;
}
