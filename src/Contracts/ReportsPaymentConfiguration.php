<?php

namespace Sellissimo\Core\Contracts;

interface ReportsPaymentConfiguration
{
    /** Staff-facing setup messages. Never include credential values. */
    public function configurationWarnings(string $site): array;
}
