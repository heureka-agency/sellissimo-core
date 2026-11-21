<?php

namespace Sellissimo\Core\Contracts;

interface ConfiguresDiscountRule extends DiscountRule
{
    public function label(): string;

    /** Keyed settings with label, type (integer, text, select or toggle), optional default, required, min, max or options. */
    public function fields(): array;
}
