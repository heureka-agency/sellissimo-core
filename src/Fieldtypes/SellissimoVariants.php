<?php

namespace Sellissimo\Core\Fieldtypes;

use Statamic\Fieldtypes\Grid;

class SellissimoVariants extends Grid
{
    protected static $title = 'Sellissimo variants';

    public function indexComponent(): string
    {
        return 'grid';
    }
}
