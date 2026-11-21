<?php

namespace Sellissimo\Core\Contracts;

interface OrderChargeProvider
{
    /**
     * Return a named order charge or null when it does not apply.
     *
     * @return array{label: string, amount: int, tax_class?: string}|null
     */
    public function charge(array $publicQuote, string $site, ?string $customer): ?array;
}
