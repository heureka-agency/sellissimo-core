<?php

namespace Sellissimo\Core\Fieldtypes;

use Sellissimo\Core\ProductDescription;
use Statamic\Fieldtypes\Bard;

class SellissimoDescription extends Bard
{
    protected $component = 'bard';

    protected $indexComponent = 'bard';

    public function preProcess(mixed $value): array
    {
        return parent::preProcess(is_string($value) ? ProductDescription::markdown($value) : $value);
    }

    public function preProcessIndex(mixed $value): string
    {
        return ProductDescription::html($value);
    }

    protected function shouldSaveHtml(): bool
    {
        return false;
    }

    protected function performAugmentation(mixed $value, mixed $shallow): ?string
    {
        return $value === null ? null : ProductDescription::html($value);
    }

    public function process(mixed $value): ?array
    {
        $content = parent::process($value);
        if (is_array($content)) {
            ProductDescription::images($content);
        }

        return $content;
    }
}
