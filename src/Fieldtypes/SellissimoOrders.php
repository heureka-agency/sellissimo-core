<?php

namespace Sellissimo\Core\Fieldtypes;

use Sellissimo\Core\StaffSites;
use Illuminate\Support\Str;
use Sellissimo\Core\Http\OrderListing;
use Sellissimo\Core\OrderNumber;
use Sellissimo\Core\Store;
use Statamic\CP\Column;
use Statamic\Fieldtypes\Relationship;

class SellissimoOrders extends Relationship
{
    protected static $title = 'Sellissimo orders';
    protected $itemComponent = 'sellissimo-related-order';
    protected $canSearch = true;

    protected function configFieldItems(): array
    {
        return ['max_items' => ['type' => 'integer', 'display' => __('Max Items'), 'min' => 1]];
    }

    private function row(object $order): array
    {
        return ['id' => $order->id, 'title' => OrderNumber::display($order), 'site' => $order->site,
            'edit_url' => cp_route('sellissimo.order', $order->id)];
    }

    protected function authorizeItemData($id): bool
    {
        return Str::isUuid($id) && request()->user()?->can('view sellissimo orders');
    }

    protected function toItemArray($id)
    {
        $row = app(Store::class)->table('orders')->whereIn('site', StaffSites::handles())->where('id', $id)->first(['id', 'order_number', 'site']);
        return $row ? $this->row($row) : $this->invalidItemArray($id);
    }

    public function preProcessIndex($data)
    {
        return $this->getItemData($data);
    }

    public function getIndexItems($request)
    {
        abort_unless($request->user()?->can('view sellissimo orders'), 403);
        $input = $request->validate(['search' => 'nullable|string|max:254', 'site' => 'nullable|string|max:100',
            'exclusions' => 'sometimes|array', 'exclusions.*' => 'uuid']);
        $orders = app(OrderListing::class)->forStaff(['q' => $input['search'] ?? null, 'site' => $input['site'] ?? null])
            ->whereNotIn('id', $input['exclusions'] ?? [])->latest('created_at')->orderBy('id');
        return $orders->paginate(25, ['id', 'order_number', 'site'])->through(fn ($row) => $this->row($row));
    }

    protected function getColumns()
    {
        return [Column::make('title')->label(__('Order'))->sortable(false), Column::make('site')->label(__('Site'))->sortable(false)];
    }
}
