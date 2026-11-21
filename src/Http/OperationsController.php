<?php

namespace Sellissimo\Core\Http;

use Sellissimo\Core\StaffSites;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Sellissimo\Core\Inventory;
use Sellissimo\Core\Money;
use Sellissimo\Core\SalesReport;
use Sellissimo\Core\Store;
use Statamic\Facades\Site;

class OperationsController
{
    public function __construct(private Store $store) {}

    public function inventory(Request $r, Inventory $inventory)
    {
        $input = $r->validate(['sku' => 'nullable|string|max:100', 'location' => 'nullable|string|max:40']);
        $sku = strtoupper($input['sku'] ?? '');
        $queue = QueueContext::read($r, 'inventory');
        $context = $queue ? ['queue' => $queue] : [];
        $allowed = $inventory->staffSkus();
        abort_if($sku !== '' && $allowed !== null && !in_array($sku, $allowed, true), 404);
        $locations = $this->store->table('locations')->orderBy('name')->pluck('name', 'code');
        $balances = $sku ? $inventory->balances($sku, (int) $this->store->table('stock')->where('sku', $sku)->value('available')) : [];
        $movements = $this->store->table('movements')
            ->when($allowed !== null, fn ($q) => $q->whereIn('sku', $allowed)->where(fn ($q) => $q->whereNull('order_id')
                ->orWhereIn('order_id', $this->store->table('orders')->whereIn('site', StaffSites::handles())->select('id'))))
            ->when($sku, fn ($q) => $q->where('sku', $sku))->when($input['location'] ?? null, fn ($q, $at) => $q->where('location', $at))
            ->latest('id')->paginate(30)->withQueryString()->through(function ($row) use ($r) {
                return [...(array) $row, 'orderUrl' => $row->order_id && $r->user()->can('view sellissimo orders') ? cp_route('sellissimo.order', $row->order_id) : null];
            });

        return Inertia::render('sellissimo::Locations', ['locations' => $locations, 'balances' => $balances, 'movements' => $movements, 'sku' => $sku,
            'skus' => $this->store->table('stock')->when($allowed !== null, fn ($q) => $q->whereIn('sku', $allowed))->orderBy('sku')->pluck('sku'), 'requestId' => (string) Str::uuid(),
            'listUrl' => cp_route('sellissimo.locations', $context), 'inventoryUrl' => cp_route('sellissimo.inventory', $queue), 'countUrl' => cp_route('sellissimo.location.count', $context), 'transferUrl' => cp_route('sellissimo.transfer', $context), 'createUrl' => cp_route('sellissimo.location.create')]);
    }

    public function createLocation(Request $r)
    {
        $input = $r->validate(['name' => 'required|string|max:100']);
        $code = Str::slug($input['name']);
        if (! $code || strlen($code) > 40 || ! $this->store->table('locations')->insertOrIgnore(['code' => $code, 'name' => $input['name']])) {
            throw ValidationException::withMessages(['name' => 'Choose a distinct location name with a handle of up to 40 characters.']);
        }

        return back();
    }

    public function countLocation(Request $r, Inventory $inventory)
    {
        $input = $r->validate(['sku' => 'required|string|max:100', 'location' => 'required|string|max:40', 'quantity' => 'required|integer|min:0|max:999999999', 'previous' => 'required|integer|min:0', 'reason' => 'required|string|max:150']);
        $sku = strtoupper($input['sku']);
        $inventory->authorizeStaffSku($sku);
        abort_unless($this->store->table('stock')->where('sku', $sku)->exists() && $this->store->table('locations')->where('code', $input['location'])->exists(), 422, 'Choose a recorded SKU and location.');
        $inventory->countLocation($sku, $input['location'], (int) $input['quantity'], (int) $input['previous'], $input['reason'], $r->user()->email());

        return redirect(cp_route('sellissimo.locations', ['sku' => $sku, 'queue' => QueueContext::read($r, 'inventory')]));
    }

    public function transfer(Request $r, Inventory $inventory)
    {
        $input = $r->validate(['request_id' => 'required|uuid', 'sku' => 'required|string|max:100', 'from' => 'required|string|max:40', 'to' => 'required|string|max:40|different:from', 'quantity' => 'required|integer|min:1|max:999999999']);
        $input['sku'] = strtoupper($input['sku']);
        $inventory->authorizeStaffSku($input['sku']);
        abort_unless($this->store->table('locations')->whereIn('code', [$input['from'], $input['to']])->count() === 2, 422, 'Choose two existing locations.');
        $inventory->transfer($input, $r->user()->email());

        return redirect(cp_route('sellissimo.locations', ['sku' => $input['sku'], 'queue' => QueueContext::read($r, 'inventory')]));
    }

    public function sales(Request $r, SalesReport $report)
    {
        $input = $r->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d', 'site' => 'nullable|string|max:100']);
        $from = $input['from'] ?? now()->startOfMonth()->toDateString();
        $to = $input['to'] ?? now()->toDateString();
        $site = $input['site'] ?? null;
        if ($from > $to) {
            throw ValidationException::withMessages(['to' => 'Choose an end date on or after the start date.']);
        }
        abort_if($site && ! Site::all()->has($site), 422);
        $reportSites = $site ?? StaffSites::handles();
        $data = $report->period($from, $to, $reportSites);
        $start = \Illuminate\Support\Carbon::parse($from);
        $days = (int) $start->diffInDays(\Illuminate\Support\Carbon::parse($to)) + 1;
        $previousFrom = $start->copy()->subDays($days)->toDateString();
        $previousTo = $start->copy()->subDay()->toDateString();
        $previous = $report->period($previousFrom, $previousTo, $reportSites);
        $trend = [];
        foreach (array_unique([...array_keys($data['currencies']), ...array_keys($previous['currencies'])]) as $currency) {
            $before = $previous['currencies'][$currency] ?? [];
            $data['currencies'][$currency] ??= array_fill_keys(array_keys($before), 0);
            $trend[$currency] = [
                'current' => (object) ($data['daily'][$currency] ?? []),
                'previous' => (object) ($previous['daily'][$currency] ?? []),
                'previousFrom' => $previousFrom, 'previousTo' => $previousTo, 'digits' => Money::fractionDigits($currency),
                'net' => Money::display($before['net'] ?? 0, $currency), 'orders' => $before['orders'] ?? 0,
                'refunds' => Money::display($before['refunds'] ?? 0, $currency),
            ];
        }
        unset($data['daily']);
        $format = fn ($metrics, $currency) => collect($metrics)->map(fn ($value, $key) => in_array($key, ['label', 'sku', 'qty', 'orders'], true) ? $value : Money::display($value, $currency))->all();
        foreach ($data['currencies'] as $currency => &$metrics) {
            $metrics = $format($metrics, $currency);
        }
        unset($metrics);
        foreach (['products', 'variants'] as $group) {
            foreach ($data[$group] as $currency => &$rows) {
                $rows = collect($rows)->sortByDesc('net')->map(fn ($row) => $format($row, $currency))->values()->all();
            }
        }
        unset($rows);
        $data['undated'] = $data['undated']->map(fn ($amount, $cur) => Money::display((int) $amount, $cur));

        return Inertia::render('sellissimo::SalesReport', [...$data, 'previousCustomers' => $previous['customers'], 'trend' => $trend, 'filters' => compact('from', 'to', 'site'), 'sites' => StaffSites::allowed()->map->name(), 'listUrl' => cp_route('sellissimo.sales')]);
    }
}
