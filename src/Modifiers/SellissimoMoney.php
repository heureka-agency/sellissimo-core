<?php

namespace Sellissimo\Core\Modifiers;

use Sellissimo\Core\Catalog;
use Sellissimo\Core\Money;
use Statamic\Facades\Site;
use Statamic\Modifiers\Modifier;

class SellissimoMoney extends Modifier
{
    public function index($value, $params, $context)
    {
        $currency = strtoupper($params[0] ?? $context['currency'] ?? app(Catalog::class)->currency(Site::current()->handle()));

        return Money::display((int) $value, $currency);
    }
}
