<?php

namespace Sellissimo\Core\Widgets;

use Sellissimo\Core\StaffSites;
use Sellissimo\Core\Inventory;
use Sellissimo\Core\Store;
use Statamic\Facades\User;
use Sellissimo\Core\StoreWidget;

class SellissimoLowStock extends StoreWidget
{
    protected const PERMISSION = 'manage sellissimo inventory';

    protected function data(Store $store): array
    {
        $threshold = max(0, (int) $this->config('threshold', \Sellissimo\Core\OperationalSettings::shared('low_stock_threshold', 'low_stock_threshold', 5)));
        $site = StaffSites::selected();
        $rows = app(Inventory::class)->catalogLevels($site, User::current())
            ->where('available', '<=', $threshold)->sortBy([['available', 'asc'], ['sku', 'asc']])->take(8)
            ->map(fn ($row) => [[
                'label' => $row['sku'],
                'title' => $row['title'],
                'url' => cp_route('sellissimo.inventory', ['site' => $site, 'q' => $row['sku']]),
            ], $row['available']])->values();

        return [
            'layout' => 'stock',
            'icon' => 'money-cashier-bar-code',
            'title' => 'Low stock',
            'columns' => ['SKU', 'Available'],
            'rows' => $rows,
            'empty' => __('No SKUs at or below :threshold units.', ['threshold' => $threshold]),
            'site' => $site,
            'url' => cp_route('sellissimo.inventory', compact('site')),
            'action' => 'View inventory',
        ];
    }
}
