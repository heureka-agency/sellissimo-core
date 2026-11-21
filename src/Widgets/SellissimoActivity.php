<?php

namespace Sellissimo\Core\Widgets;

use Illuminate\Support\Carbon;
use Sellissimo\Core\Http\OrderListing;
use Sellissimo\Core\StaffSites;
use Sellissimo\Core\Money;
use Sellissimo\Core\OrderNumber;
use Sellissimo\Core\SalesReport;
use Sellissimo\Core\Store;
use Statamic\Facades\User;
use Statamic\Widgets\Widget;

class SellissimoActivity extends Widget
{
    public function html()
    {
        $user = User::current();
        if (!$user || StaffSites::allowed($user)->isEmpty() || !$user->canAny(['view sellissimo orders', 'view sellissimo sales'])) {
            return '';
        }
        $store = app(Store::class);
        if (!$store->hasTable('orders')) {
            return '';
        }
        $site = StaffSites::selected();
        $days = (int) request()->query('sellissimo_days', $this->config('days', 30));
        if (!in_array($days, [7, 30, 90], true)) {
            $days = 30;
        }
        $from = now()->subDays($days - 1)->startOfDay();
        $recent = $user->can('view sellissimo orders') ? app(OrderListing::class)->forStaff(['site' => $site, 'from' => $from->toDateString()])
            ->orderByDesc('created_at')->orderByDesc('id')->limit(5)
            ->get(['id', 'order_number', 'created_at', 'status', 'payment_state', 'total', 'currency'])->map(fn ($row) => [
                'number' => OrderNumber::display($row), 'url' => cp_route('sellissimo.order', $row->id),
                'date' => Carbon::parse($row->created_at)->locale(app()->getLocale())->isoFormat('ll'),
                'status' => $row->status, 'payment' => $row->payment_state, 'total' => Money::display($row->total, $row->currency),
            ]) : null;
        $customers = null;
        $refunds = [];
        if ($user->can('view sellissimo sales')) {
            $end = now()->addDay()->startOfDay();
            $customers = app(SalesReport::class)->customerCounts($from->toDateTimeString(), $end->toDateTimeString(), $site);
            $refunds = $store->table('refunds')->join('sellissimo_orders as o', 'o.id', '=', 'sellissimo_refunds.order_id')
                ->where('o.site', $site)->where('sellissimo_refunds.status', 'succeeded')
                ->where('settled_at', '>=', $from)->where('settled_at', '<', $end)
                ->groupBy('o.currency')->orderBy('o.currency')->select('o.currency')->selectRaw('sum(amount) as refunded')
                ->pluck('refunded', 'currency');
        }
        $scope = \Statamic\Facades\Site::all()->count() > 1 ? StaffSites::allowed()->get($site)->name() : null;

        return view('sellissimo::activity-widget', compact('days', 'recent', 'customers', 'refunds', 'scope'));
    }
}
