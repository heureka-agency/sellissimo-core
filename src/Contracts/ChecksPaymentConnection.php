<?php

namespace Sellissimo\Core\Contracts;

interface ChecksPaymentConnection
{
    /** Verify a credential using a read-only provider call and return its stable account identifier. */
    public static function checkConnection(array $credentials): string;
}
