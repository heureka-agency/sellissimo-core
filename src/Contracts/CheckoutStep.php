<?php

namespace Sellissimo\Core\Contracts;

interface CheckoutStep
{
    public function label(): string;

    public function applies(array $publicQuote, string $site): bool;

    /** Keyed controls with label, type (text, date or select), required, options and optional Laravel rules. */
    public function fields(array $publicQuote, string $site): array;
}
