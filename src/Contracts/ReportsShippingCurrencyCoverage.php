<?php

namespace Sellissimo\Core\Contracts;

interface ReportsShippingCurrencyCoverage
{
    /** Each configured service returns label, priced, pay_on_delivery, and free in the requested currency. */
    public function currencyCoverage(string $site, string $currency): array;
}
