<?php

namespace Sellissimo\Core\Http;

use Sellissimo\Core\StaffSites;
use Illuminate\Database\Query\Builder;
use Sellissimo\Core\Store;

class OrderListing
{
    public const COLUMNS = ['customer' => 'Customer', 'payment' => 'Payment', 'fulfillment' => 'Fulfillment',
        'total' => 'Total', 'site' => 'Site', 'gateway' => 'Payment method', 'date' => 'Order date'];
    public const DEFAULT_COLUMNS = 'order,customer,payment,fulfillment,total';

    public static function columns(string $columns): string
    {
        $selected = array_intersect(explode(',', $columns), array_keys(self::columnOptions()));

        return implode(',', ['order', ...array_values(array_unique($selected))]);
    }

    public static function columnOptions(): array
    {
        $fields = app(\Sellissimo\Core\OrderQueueFields::class)->definitions();
        return self::COLUMNS + collect($fields)->mapWithKeys(fn ($field, $handle) => ['field_'.$handle => $field['label']])->all();
    }

    public const FILTERS = [
        'q' => 'nullable|string|max:254',
        'site' => 'nullable|alpha_dash|max:100',
        'status' => 'nullable|in:paid,pending,refunded,cancelled,expired,awaiting_collection',
        'view' => 'nullable|in:awaiting,shipped,returns,disputes',
        'payment' => 'nullable|in:paid,pending,unpaid,awaiting_collection,refunded,partially_refunded,refund_pending,refund_due',
        'from' => 'nullable|date_format:Y-m-d',
        'to' => 'nullable|date_format:Y-m-d',
        'columns' => ['nullable', 'string', 'max:255', 'regex:/^order(?:,(?:customer|payment|fulfillment|total|site|gateway|date|field_[a-zA-Z0-9_]+))*$/D'],
        'field' => 'nullable|alpha_dash|max:150', 'field_value' => 'nullable|string|max:4000',
        'sort' => 'nullable|in:newest,oldest,number',
    ];

    public function __construct(private Store $store) {}

    public function forStaff(array $filters): Builder
    {
        if (filled($filters['site'] ?? null)) {
            StaffSites::authorize($filters['site']);
        }

        return $this->query($filters)->whereIn('site', StaffSites::handles());
    }

    public function query(array $filters): Builder
    {
        $trackingStatus = $this->store->db()->getQueryGrammar()->wrap('label.tracking_status');
        $reserved = $this->store->table('refunds')->whereColumn('order_id', 'sellissimo_orders.id')
            ->whereIn('status', ['pending', 'succeeded']);
        $received = $this->store->table('return_requests')->whereColumn('order_id', 'sellissimo_orders.id')
            ->where('status', 'received')->whereNull('replacement');
        $due = (clone $received)->where('sellissimo_orders.status', 'paid')
            ->whereNotExists((clone $reserved)->where('status', 'pending')->whereColumn('return_request_id', 'sellissimo_return_requests.id'))
            ->whereNotExists((clone $reserved)->where('sku', '@all'))
            ->where((clone $reserved)->selectRaw('coalesce(sum(amount), 0)'), '<', $this->column('sellissimo_orders.total'))
            ->where((clone $reserved)->whereColumn('sku', 'sellissimo_return_requests.line_key')->selectRaw('coalesce(sum(amount), 0)'), '<', $this->column('sellissimo_return_requests.refund_line_total'))
            ->where((clone $reserved)->whereColumn('return_request_id', 'sellissimo_return_requests.id')->selectRaw('coalesce(sum(amount), 0)'), '<', $this->column('sellissimo_return_requests.refund_entitlement'));
        $rows = $this->store->table('orders')->select('sellissimo_orders.*')
            ->selectSub($this->store->table('refunds')->whereColumn('order_id', 'sellissimo_orders.id')
                ->where('status', 'succeeded')->selectRaw('coalesce(sum(amount), 0)'), 'refund_amount')
            ->selectSub($this->store->table('refunds')->whereColumn('order_id', 'sellissimo_orders.id')
                ->where('status', 'pending')->selectRaw('count(*)'), 'refund_pending_count')
            ->selectSub($due->selectRaw('count(*)'), 'due_returns')
            ->selectSub($this->store->table('disputes')->whereColumn('order_id', 'sellissimo_orders.id')
                ->whereNotIn('status', ['won', 'warning_closed', 'prevented', 'chargeback_reversed'])->selectRaw('count(*)'), 'dispute_alerts')
            ->selectSub($this->store->table('returns')->whereColumn('order_id', 'sellissimo_orders.id')
                ->selectRaw('coalesce(sum(quantity), 0)'), 'returned_units')
            ->selectSub($this->store->table('parcels')->whereColumn('order_id', 'sellissimo_orders.id')->selectRaw('count(*)'), 'parcel_count')
            ->selectSub($this->store->table('parcels')->leftJoin('sellissimo_labels as label', 'label.id', '=', 'sellissimo_parcels.id')
                ->whereColumn('sellissimo_parcels.order_id', 'sellissimo_orders.id')
                ->where(fn ($q) => $q->whereNotNull('sellissimo_parcels.delivered_at')->orWhereRaw("lower(trim($trackingStatus)) = 'delivered'"))
                ->selectRaw('count(*)'), 'delivered_parcels')
            ->selectSub($this->store->table('return_requests')->whereColumn('order_id', 'sellissimo_orders.id')
                ->where(fn ($q) => $q->whereIn('status', ['requested', 'approved'])
                    ->orWhere(fn ($q) => $q->where('status', 'received')->whereNotNull('replacement'))
                    ->orWhere(fn ($q) => $q->where('status', 'received')->whereExists(
                        (clone $reserved)->where('status', 'pending')->whereColumn('return_request_id', 'sellissimo_return_requests.id')
                    )))
                ->selectRaw('count(*)'), 'active_returns');
        $states = $this->store->db()->query()->fromSub($rows, 'ledger')->select('ledger.*')
            ->selectRaw('active_returns + due_returns as open_returns')
            ->selectRaw("case
                when refund_pending_count > 0 then 'refund_pending'
                when status = 'refunded' or (refund_amount > 0 and refund_amount >= total) then 'refunded'
                when due_returns > 0 then 'refund_due'
                when status = 'cancelled' and (paid_at is not null or cancellation_reason is not null) and total > refund_amount then 'refund_due'
                when refund_amount > 0 then 'partially_refunded'
                when status = 'cancelled' and paid_at is not null and total = 0 then 'paid'
                when status in ('expired', 'cancelled') then 'unpaid'
                else status end as payment_state");
        $orders = $this->store->db()->query()->fromSub($states, 'sellissimo_orders');
        if (filled($filters['field'] ?? null) && filled($filters['field_value'] ?? null)) {
            // A removed blueprint field must never widen a saved queue or its export.
            if (!array_key_exists($filters['field'], app(\Sellissimo\Core\OrderQueueFields::class)->definitions())) {
                $orders->whereRaw('1 = 0');
            } else {
                $orders->whereIn('id', $this->store->table('queue_fields')->select('order_id')->where('handle', $filters['field'])
                    ->where('term', hash('sha256', \Illuminate\Support\Str::lower(trim($filters['field_value'])))));
            }
        }
        if ($filters['payment'] ?? null) {
            $orders->where('payment_state', $filters['payment']);
        }
        if ($filters['from'] ?? null) {
            $orders->where('created_at', '>=', $filters['from'].' 00:00:00');
        }
        if ($filters['to'] ?? null) {
            $orders->where('created_at', '<', \Illuminate\Support\Carbon::parse($filters['to'])->addDay()->startOfDay());
        }
        if (($filters['view'] ?? '') === 'returns') {
            $orders->where('open_returns', '>', 0);
        }
        if (($filters['view'] ?? '') === 'disputes') {
            $orders->where('dispute_alerts', '>', 0);
        }
        if ($site = $filters['site'] ?? null) {
            $orders->where('site', $site);
        }
        if (filled($filters['q'] ?? null)) {
            $search = ltrim(trim($filters['q']), '#');
            $term = '%'.$search.'%';
            $name = $orders->getGrammar()->wrap('receipt->address->name');
            $orders->where(fn ($query) => $query->whereLike('email', $term)->orWhereLike('id', $term)
                ->orWhereRaw("lower($name) like lower(?)", [$term])
                ->when(ctype_digit($search) && strlen($search) < 19, fn ($q) => $q->orWhere('order_number', (int) $search)));
        }
        if ($status = $filters['status'] ?? null) {
            $orders->where('status', $status);
        }
        if (($filters['view'] ?? null) === 'awaiting') {
            $orders->whereIn('status', ['paid', 'awaiting_collection'])->where(fn ($q) => $q->whereNull('tracking')->orWhere('partially_shipped', true))->whereNotNull('receipt->shipping->amount');
        } elseif (($filters['view'] ?? null) === 'shipped') {
            $orders->whereNotNull('tracking')->where('partially_shipped', false);
        }

        return $orders;
    }

    private function column(string $name): \Illuminate\Database\Query\Expression
    {
        return $this->store->db()->raw($this->store->db()->getQueryGrammar()->wrap($name));
    }
}
