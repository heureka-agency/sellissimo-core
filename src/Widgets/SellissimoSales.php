<?php

namespace Sellissimo\Core\Widgets;

use Sellissimo\Core\StaffSites;
use Sellissimo\Core\Money;
use Sellissimo\Core\SalesReport;
use Sellissimo\Core\Store;
use Sellissimo\Core\StoreWidget;

class SellissimoSales extends StoreWidget
{
    protected const PERMISSION = 'view sellissimo sales';

    protected function data(Store $store): array
    {
        $refunds = $store->table('refunds')->join('sellissimo_orders as o', 'o.id', '=', 'sellissimo_refunds.order_id')
            ->whereIn('o.site', StaffSites::handles())->where('sellissimo_refunds.status', 'succeeded')->groupBy('o.currency')->selectRaw('sum(amount) as amount')->addSelect('o.currency')->pluck('amount', 'currency');
        $rows = app(SalesReport::class)->paidOrders(StaffSites::handles())->groupBy('currency')->orderBy('currency')->select('currency')->selectRaw('sum(total) as gross')->get()
            ->map(fn ($row) => [$row->currency, Money::display($row->gross, $row->currency), Money::display($refunds[$row->currency] ?? 0, $row->currency)]);

        return [
            'layout' => 'sales',
            'icon' => 'money-cashier-calculator',
            'title' => 'Lifetime sales',
            'columns' => ['Currency', 'Paid sales', 'Refunds'],
            'rows' => $rows,
            'empty' => 'No paid orders yet.',
            'url' => cp_route('sellissimo.sales'),
            'action' => 'View sales report',
        ];
    }
}
