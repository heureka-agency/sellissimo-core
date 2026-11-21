<?php

namespace Sellissimo\Core\Testing;

use Sellissimo\Core\Contracts\ShippingProvider;

/** Mix into an extension's PHPUnit test and supply a supported destination and currency. */
trait ShippingProviderContract
{
    abstract protected function shippingProvider(): ShippingProvider;

    abstract protected function quoteFixture(): array;

    public function test_provider_returns_selectable_minor_unit_quotes(): void
    {
        [$lines,$address,$currency] = $this->quoteFixture();
        $quotes = $this->shippingProvider()->quotes($lines, $address, $currency);
        self::assertNotEmpty($quotes);
        foreach ($quotes as $code => $quote) {
            self::assertIsString($code);
            self::assertNotEmpty($quote['label']);
            self::assertIsInt($quote['amount']);
            self::assertGreaterThanOrEqual(0, $quote['amount']);
        }
    }
}
