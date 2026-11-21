<?php

namespace Sellissimo\Core\Http;

use Sellissimo\Core\StaffSites;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Sellissimo\Core\Checkout;
use Sellissimo\Core\ExchangeParcels;
use Sellissimo\Core\Inventory;
use Sellissimo\Core\LineOptions;
use Sellissimo\Core\Refunds;
use Sellissimo\Core\ReturnRequests;
use Sellissimo\Core\ReturnNotices;
use Sellissimo\Core\Catalog;
use Sellissimo\Core\Store;

class ReturnsController
{
    public function __construct(private Store $store, private ReturnRequests $returns) {}

    public function buyer(Request $request, string $order, Checkout $checkout)
    {
        $row = $checkout->order($order);
        PrivateResponse::forSite($row->site);
        $accountOrder = $row->customer !== null && $row->customer === (string) $request->user()?->getAuthIdentifier();
        abort_unless($accountOrder || \Sellissimo\Core\OrderLinks::valid($request, $row), 404);
        if ($request->isMethod('post')) {
            $input = $request->validate(['request_id' => 'required|uuid', 'line_key' => 'required|string|max:100', 'quantity' => 'required|integer|min:1|max:999', 'reason' => 'required|string|max:1000', 'replacement' => 'nullable|string|max:100']);
            $this->returns->submit($order, $input);
            if (! $request->expectsJson()) {
                return redirect()->to($request->fullUrl());
            }
        }
        $lines = json_decode($row->receipt, true)['lines'];
        $requests = $this->returns->forOrder($order);
        $history = app(ReturnNotices::class)->history($requests->pluck('id')->all());
        $parcels = app(ExchangeParcels::class)->history($requests->pluck('id')->all());
        $requests->each(function ($entry) use ($lines, $row, $history, $parcels) {
            $entry->parcels = $parcels->get($entry->id, collect())->map(fn ($parcel) => ['quantity' => (int) $parcel->quantity, 'tracking' => $parcel->tracking, 'dispatched_at' => $parcel->dispatched_at])->values();
            if ($entry->inbound_label_path) {
                $entry->inbound_label_url = \Sellissimo\Core\OrderLinks::signed($row, 'sellissimo.buyer.return-label',
                    now()->setTimestamp(min(now()->addDays(\Sellissimo\Core\OperationalSettings::accessDays($row))->timestamp, (int) request()->query('expires', PHP_INT_MAX))),
                    ['order' => $row->id, 'return' => $entry->id]);
            }
            unset($entry->reserved_stock, $entry->inbound_label_path, $entry->inbound_label_disk);
            $entry->history = $history->get($entry->id, []);
            $entry->balance = app(\Sellissimo\Core\ExchangeBalances::class)->details($row, $entry);
            $entry->refund = app(Refunds::class)->returnSummary($row, $entry);
            $line = LineOptions::find($lines, $entry->line_key);
            $entry->title = $line ? trim($line['title'].' '.implode(' / ', array_column($line['options'] ?? [], 'value'))) : $entry->line_key;
        });
        $data = ['policies' => app(\Sellissimo\Core\ReturnPolicy::class)->forOrder($row, app(\Sellissimo\Core\Fulfillment::class)->parcels($order)), 'requests' => $requests, 'choices' => $this->returns->choices($row), 'requestId' => (string) Str::uuid(), 'url' => $request->fullUrl()];

        if ($request->expectsJson()) {
            return response()->json($data);
        }
        $receiptUrl = $accountOrder ? route('sellissimo.receipt', ['order' => $order]) : null;
        if (!$accountOrder && $request->query('from_receipt') === '1') {
            // The validated return link inherits the receipt access and expiry of its signed parent.
            $expires = min(now()->addDays(\Sellissimo\Core\OperationalSettings::accessDays($row))->timestamp, (int) $request->query('expires'));
            $receiptUrl = \Sellissimo\Core\OrderLinks::signed($row, 'sellissimo.receipt', now()->setTimestamp($expires), ['order' => $order]);
        }

        return view('sellissimo::return-requests', [...$data, 'account_order' => $accountOrder,
            'number' => \Sellissimo\Core\OrderNumber::display($row), 'receiptUrl' => $receiptUrl, 'site' => $row->site]);
    }

    public function label(Request $request, string $order, string $return, Checkout $checkout)
    {
        $row = $checkout->order($order);
        abort_unless(($request->user() && (string) $request->user()->getAuthIdentifier() === $row->customer)
            || \Sellissimo\Core\OrderLinks::valid($request, $row), 404);
        $case = $this->store->table('return_requests')->where('id', $return)->where('order_id', $order)->firstOrFail();
        abort_unless($case->inbound_label_path, 404);
        return \Illuminate\Support\Facades\Storage::disk($case->inbound_label_disk)->download($case->inbound_label_path, 'return-label.pdf', ['X-Content-Type-Options' => 'nosniff']);
    }

    public function index(Request $request)
    {
        $filters = $request->validate(['status' => 'nullable|in:requested,approved,declined,received,exchanged', 'order' => 'nullable|uuid']);
        $sites = StaffSites::allowed();
        $site = $request->validate(['site' => ['sometimes', \Illuminate\Validation\Rule::in($sites->keys()->all())]])['site'] ?? StaffSites::selected();
        if (!empty($filters['order'])) {
            $site = $this->store->table('orders')->where('id', $filters['order'])->value('site') ?? $site;
            StaffSites::authorize($site);
        }
        $rows = $this->store->table('return_requests')->whereIn('order_id', $this->store->table('orders')->where('site', $site)->select('id'))->when($filters['order'] ?? null, fn ($q, $id) => $q->where('order_id', $id))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))->latest('created_at')->orderByDesc('id')->paginate(25)->withQueryString();
        $orders = $this->store->table('orders')->whereIn('id', $rows->pluck('order_id'))->get()->keyBy('id');
        $canManage = $request->user()->can('fulfill sellissimo orders');
        $needsProducts = $canManage && $rows->contains(fn ($row) => $row->replacement && in_array($row->status, ['requested', 'approved', 'received'], true));
        $products = $needsProducts ? app(Catalog::class)->products($site, false) : [];
        $history = app(ReturnNotices::class)->history($rows->pluck('id')->all());
        foreach ($history->collapse() as $event) {
            $event->displayDate = $event->created_at ? \Illuminate\Support\Carbon::parse($event->created_at)->locale(app()->getLocale())->isoFormat('lll') : null;
        }
        $parcels = app(ExchangeParcels::class)->history($rows->pluck('id')->all());
        $queue = [...$filters, 'site' => $site];
        if ($rows->currentPage() > 1) $queue['page'] = $rows->currentPage();
        $case = $request->validate(['case' => 'nullable|uuid'])['case'] ?? null;
        $rows->through(function ($row) use ($orders, $request, $products, $history, $parcels, $queue) {
            $order = $orders[$row->order_id];
            $line = LineOptions::find(json_decode($order->receipt, true)['lines'], $row->line_key);

            $refunds = app(Refunds::class);
            $refund = $refunds->returnSummary($order, $row);
            $alternatives = $row->replacement && $line
                ? collect($this->returns->replacements($order, $line, $products, $row->quantity))->reject(fn ($p) => $p['sku'] === $row->replacement)->values()
                : [];
            $balance = app(\Sellissimo\Core\ExchangeBalances::class)->details($order, $row);
            return [...(array) $row, 'orderNumber' => \Sellissimo\Core\OrderNumber::display($order),
                'requestedDate' => $row->created_at ? \Illuminate\Support\Carbon::parse($row->created_at)->locale(app()->getLocale())->isoFormat('ll') : null, 'parcels' => $parcels->get($row->id, []),
                'reservedAt' => json_decode($row->reserved_stock ?? '[]', true)[$row->replacement] ?? [], 'balanceGateways' => $balance && $balance['difference'] > 0
                    ? app(\Sellissimo\Core\Amendments::class)->balanceGateways(json_decode($order->receipt, true), $balance['difference'], $order->site) : [],
                'canRefundBalance' => $balance && $balance['difference'] < 0 && $refunds->canRefundOnline($order, -$balance['difference']),
                'alternatives' => $alternatives, 'operationId' => (string) Str::uuid(), 'balance' => $balance,
                'balanceUrl' => $request->user()->can('refund sellissimo orders') ? cp_route('sellissimo.returns.balance', $row->id) : null, 'history' => $history->get($row->id, []), 'refund' => $refund, 'refundableOnline' => $refund && $refunds->canRefundOnline($order, $refund['due']),
                'tracked' => $line && Inventory::tracked($line),
                'refundUrl' => $request->user()->can('refund sellissimo orders') ? cp_route('sellissimo.returns.refund', $row->id) : null,
                'refundRequestId' => (string) Str::uuid(), 'email' => $order->email, 'title' => $line ? trim($line['title'].' '.implode(' / ', array_column($line['options'] ?? [], 'value'))) : $row->line_key, 'orderUrl' => $request->user()->can('view sellissimo orders') ? cp_route('sellissimo.order', ['order' => $order->id, 'origin' => 'returns', 'queue' => [...$queue, 'case' => $row->id]]) : null, 'actionUrl' => $request->user()->can('fulfill sellissimo orders') ? cp_route('sellissimo.returns.action', $row->id) : null];
        });

        return Inertia::render('sellissimo::Returns', ['records' => $rows, 'selectedCase' => $case && $rows->contains('id', $case) ? $case : null, 'locations' => $this->store->table('locations')->orderBy('name')->pluck('name', 'code'), 'policySite' => $site, 'sites' => $sites->map->name(),
            'policyDays' => app(\Sellissimo\Core\ReturnPolicy::class)->days($site),
            'policyUrl' => $request->user()->can('manage sellissimo') ? cp_route('sellissimo.returns.policy') : null, 'filters' => [...$filters, 'site' => $site], 'listUrl' => cp_route('sellissimo.returns')]);
    }

    public function refund(Request $request, string $id, Refunds $refunds)
    {
        $input = $request->validate(['request_id' => 'required|uuid', 'amount' => 'required|integer|min:1', 'manual' => 'sometimes|boolean']);
        $row = $this->store->table('return_requests')->where('id', $id)->firstOrFail();
        $manual = $request->boolean('manual')
            ? [...$request->validate(['reason' => 'required|string|max:250', 'reference' => 'required|string|max:150']), 'author' => $request->user()->email()] : null;
        $refunds->request($row->order_id, $input['request_id'], $row->line_key, (int) $input['amount'], $manual, $id);
        return back();
    }

    public function balance(Request $request, string $id, \Sellissimo\Core\ExchangeBalances $balances)
    {
        $data = $request->validate(['gateway' => 'nullable|string|max:80', 'reference' => 'nullable|string|max:150']);
        $balances->start($id, $data, $request->user()->email());
        return back();
    }

    public function policy(Request $request)
    {
        $data = $request->validate(['site' => ['required', \Illuminate\Validation\Rule::in(\Statamic\Facades\Site::all()->keys()->all())],
            'days' => 'present|nullable|integer|between:1,3650']);
        $this->store->table('return_policies')->upsert([$data], ['site'], ['days']);
        \Statamic\Facades\CP\Toast::success(__('Return policy saved.'));
        return back();
    }

    public function update(Request $request, string $id)
    {
        $input = $request->validate(['action' => 'required|in:approve,decline,receive,dispatch,substitute,convert,message,shipping', 'message' => 'required_if:action,message|nullable|string|max:1000',
            'inbound_label_url' => 'nullable|url:https|max:4096', 'inbound_tracking_url' => 'nullable|url:https|max:4096',
            'inbound_label' => 'nullable|file|mimes:pdf|max:10240', 'inbound_tracking' => 'nullable|string|max:200', 'operation_id' => 'required_if:action,message,substitute,convert,shipping|nullable|uuid', 'replacement' => 'required_if:action,substitute|nullable|string|max:100',
            'origin' => 'nullable|string|max:40', 'quantity' => 'nullable|integer|min:1|max:999',
            'location' => 'nullable|string|max:40', 'restocked' => 'required_if:action,receive|nullable|integer|min:0|max:999', 'tracking' => 'required_if:action,dispatch|nullable|string|max:200']);
        $this->returns->transition($id, $input, $request->user());

        return back();
    }
}
