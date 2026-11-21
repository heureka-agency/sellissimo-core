<?php

namespace Sellissimo\Core\Widgets;

use Illuminate\Support\Carbon;
use Sellissimo\Core\Http\OrderListing;
use Sellissimo\Core\OrderNumber;
use Sellissimo\Core\StaffSites;
use Sellissimo\Core\Store;
use Sellissimo\Core\StoreWidget;
use Statamic\Facades\User;

class SellissimoAwaitingShipment extends StoreWidget
{
    protected const PERMISSION = 'fulfill sellissimo orders';

    protected function data(Store $store): array
    {
        $canView = User::current()->can('view sellissimo orders');
        $sites = StaffSites::allowed();
        $rows = app(OrderListing::class)->forStaff(['view' => 'awaiting'])->oldest('created_at')->orderBy('id')->limit(8)->get()
            ->map(function ($row) use ($canView, $sites) {
                $placed = Carbon::parse($row->created_at)->locale(app()->getLocale());

                return [[
                    'label' => OrderNumber::display($row),
                    'url' => $canView ? cp_route('sellissimo.order', ['order' => $row->id, 'view' => 'awaiting']) : null,
                    'site' => $sites->count() > 1 ? $sites->get($row->site)->name() : null,
                    'date' => $placed->toDateString(),
                ], $placed->isoFormat('ll')];
            });

        return [
            'layout' => 'shipments',
            'icon' => 'package-box-crate',
            'title' => 'Awaiting shipment',
            'columns' => ['Order', 'Placed'],
            'rows' => $rows,
            'empty' => 'No orders awaiting shipment',
            'url' => $canView ? cp_route('sellissimo.orders', ['view' => 'awaiting']) : null,
            'action' => 'View shipping queue',
        ];
    }
}
