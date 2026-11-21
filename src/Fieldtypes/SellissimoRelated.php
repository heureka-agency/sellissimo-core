<?php

namespace Sellissimo\Core\Fieldtypes;

use Sellissimo\Core\Catalog;
use Statamic\Fieldtypes\Entries;

class SellissimoRelated extends Entries
{
    protected static $title = 'Related store products';

    protected function getConfiguredCollections()
    {
        return Catalog::collections();
    }
}
