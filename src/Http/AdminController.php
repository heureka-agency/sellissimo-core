<?php

namespace Sellissimo\Core\Http;

use Sellissimo\Core\StaffSites;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Sellissimo\Core\Checkout;
use Sellissimo\Core\Fulfillment;
use Sellissimo\Core\LineOptions;
use Sellissimo\Core\Money;
use Sellissimo\Core\Receipts;
use Sellissimo\Core\Refunds;
use Sellissimo\Core\Registry;
use Sellissimo\Core\ShipmentEmails;
use Sellissimo\Core\Store;
use Statamic\Facades\Collection;
use Statamic\Facades\CP\Toast;
use Statamic\Facades\Site;
use Statamic\Facades\User;

class AdminController
{
    public function __construct(private Store $store, private Checkout $checkout) {}

    public function index(Request $request, OrderListing $listing)
    {
        $filters = $request->validate(OrderListing::FILTERS);
        $defaultColumns = $this->store->table('staff_order_columns')->where('staff', $request->user()->getAuthIdentifier())->value('columns');
        $roleDefaults = config('sellissimo.order_columns_by_role', []);
        if (! isset($filters['columns']) && $defaultColumns === null && $roleDefaults) {
            $roles = User::fromUser($request->user())->roles()->keys()->all();
            $defaultColumns = collect($roleDefaults)->only($roles)->first();
        }
        $columns = OrderListing::columns($filters['columns'] ?? $defaultColumns ?? OrderListing::DEFAULT_COLUMNS);
        $orders = $listing->forStaff($filters);
        match ($filters['sort'] ?? 'newest') {
            'oldest' => $orders->oldest('created_at'),
            'number' => $orders->orderByDesc('order_number'),
            default => $orders->latest('created_at'),
        };
        $results = $orders->orderBy('id')->paginate(25)->withQueryString();
        $returnFilters = $results->currentPage() > 1 ? [...$filters, 'page' => $results->currentPage()] : $filters;
        $results->through(fn ($row) => [
            ...OrderView::summary($row, json_decode($row->receipt, true, flags: JSON_THROW_ON_ERROR)),
            ...collect(app(\Sellissimo\Core\OrderQueueFields::class)->values($row, json_decode($row->receipt, true)))
                ->mapWithKeys(fn ($value, $handle) => ['field_'.$handle => $value])->only(explode(',', $columns))->all(),
            'url' => cp_route('sellissimo.order', ['order' => $row->id, ...$returnFilters]),
        ]);

        return Inertia::render('sellissimo::Orders', [
            'results' => $results, 'filters' => (object) $filters, 'sites' => StaffSites::allowed()->map->name(), 'listUrl' => cp_route('sellissimo.orders'),
            'columns' => $columns, 'columnOptions' => OrderListing::columnOptions(), 'customFields' => app(\Sellissimo\Core\OrderQueueFields::class)->definitions(),
            'savedViews' => $this->store->table('order_views')->where('staff', $request->user()->getAuthIdentifier())->orderBy('name')->get()
                ->map(fn ($row) => ['name' => $row->name, 'url' => cp_route('sellissimo.orders', json_decode($row->filters, true)), 'deleteUrl' => cp_route('sellissimo.views.delete', $row->id)]),
            'saveViewUrl' => cp_route('sellissimo.views.save'),
            'pickUrl' => $request->user()->can('fulfill sellissimo orders') ? cp_route('sellissimo.pick-list') : null,
            'lineExportUrl' => $request->user()->can('export sellissimo orders') ? cp_route('sellissimo.export', [...$filters, 'format' => 'lines']) : null,
            'packingUrl' => $request->user()->can('fulfill sellissimo orders') ? cp_route('sellissimo.packing-slips') : null,
            'exportUrl' => $request->user()->can('export sellissimo orders') ? cp_route('sellissimo.export', $filters) : null,
        ]);
    }

    public function saveView(Request $request)
    {
        $name = $request->validate(['name' => 'required|string|max:64'])['name'];
        $filters = array_filter($request->validate(OrderListing::FILTERS), fn ($value) => $value !== null && $value !== '');
        $staff = $request->user()->getAuthIdentifier();
        $default = $request->validate(['default_columns' => 'sometimes|boolean'])['default_columns'] ?? false;
        if (isset($filters['columns'])) {
            $filters['columns'] = OrderListing::columns($filters['columns']);
        }
        $this->store->db()->transaction(function () use ($staff, $name, $filters, $default) {
            $this->store->table('order_views')->upsert([['id' => (string) Str::uuid(), 'staff' => $staff,
                'name' => $name, 'filters' => json_encode($filters, JSON_THROW_ON_ERROR)]], ['staff', 'name'], ['filters']);
            if ($default && isset($filters['columns'])) {
                $this->store->table('staff_order_columns')->upsert([['staff' => $staff, 'columns' => $filters['columns']]], ['staff'], ['columns']);
            }
        }, 3);
        return redirect(cp_route('sellissimo.orders', $filters));
    }

    public function deleteView(Request $request, string $view)
    {
        $this->store->table('order_views')->where('staff', $request->user()->getAuthIdentifier())->where('id', $view)->delete();
        return back();
    }

    public function inventory(Request $request, \Sellissimo\Core\Inventory $inventory)
    {
        $filters = $request->validate(['q' => 'nullable|string|max:100', 'view' => 'nullable|in:available,empty',
            'site' => ['sometimes', \Illuminate\Validation\Rule::in(Site::all()->keys()->all())], 'page' => 'sometimes|integer|min:1']);
        $target = $request->validate(['adjust' => 'sometimes|string|max:100'])['adjust'] ?? null;
        $site = $filters['site'] ?? StaffSites::selected();
        $levels = $inventory->catalogLevels($site, $request->user(), includeArchived: StaffSites::storewide());
        $term = trim($filters['q'] ?? '');
        $view = $filters['view'] ?? '';
        $rows = $levels->filter(fn ($row) => (! $view || ($row['title'] !== null && ($view === 'empty' ? $row['available'] === 0 : $row['available'] > 0)))
            && (! strlen($term) || Str::contains($row['sku'].' '.$row['title'], $term, ignoreCase: true)))->sortBy('sku', SORT_STRING);
        $page = (int) ($filters['page'] ?? 1);
        unset($filters['page']);
        $results = new LengthAwarePaginator($rows->forPage($page, 25)->values(), $rows->count(), 25, $page, ['path' => $request->url()]);
        $collections = collect(\Sellissimo\Core\Catalog::collections())->map(fn ($handle) => Collection::findByHandle($handle))
            ->filter(fn ($collection) => $collection && $collection->sites()->contains($site) && $request->user()->can('view', $collection))
            ->map(fn ($collection) => ['title' => $collection->title(), 'url' => $collection->showUrl()])->values();

        return Inertia::render('sellissimo::Inventory', [
            'results' => $results->appends($filters), 'filters' => $filters,
            'adjustTarget' => $target ? $levels->get(strtoupper($target)) : null,
            'products' => $levels->reject(fn ($row) => $row['recorded'])->map(fn ($row) => [
                'value' => $row['sku'], 'label' => $row['title'].' · '.$row['sku'],
            ])->values(),
            'listUrl' => cp_route('sellissimo.inventory'), 'saveUrl' => cp_route('sellissimo.stock'), 'locationsUrl' => cp_route('sellissimo.locations', ['queue' => $page > 1 ? [...$filters, 'page' => $page] : $filters]),
            'productsUrl' => $collections->first()['url'] ?? null, 'collections' => $collections,
        ]);
    }

    public function stock(Request $request)
    {
        $input = $request->validate([
            'sku' => 'required|regex:/^[A-Za-z0-9][A-Za-z0-9_.-]{0,99}$/D',
            'quantity' => 'required|integer|min:0|max:999999999',
            'mode' => 'sometimes|in:create,edit',
        ]);
        $sku = strtoupper($input['sku']);
        app(\Sellissimo\Core\Inventory::class)->authorizeStaffSku($sku);
        $quantity = (int) $input['quantity'];
        $input += $request->validate(['reason' => 'nullable|string|max:150', 'previous' => 'required_if:mode,edit|nullable|integer|min:0']);
        app(\Sellissimo\Core\Inventory::class)->setTotal($sku,$quantity,$input,$request->user()->email());
        Toast::success(__('Stock level saved.'));

        return back();
    }

    public function reconcilePayments(Request $request, string $order, string $gateway, Registry $registry)
    {
        $provider = $registry->paymentReconciler($gateway);
        abort_unless($provider, 404);
        try {
            $provider->reconcileOrder($order);
        } catch (\Illuminate\Http\Client\ConnectionException|\Illuminate\Http\Client\RequestException $error) {
            report($error);
            throw ValidationException::withMessages(['payment' => __('The payment provider could not be reached. Try refreshing again.')]);
        }
        $actor = $request->user();
        $this->store->recordActivity($order, 'payment', 'Refreshed '.Str::headline($gateway).' payment records.', $actor->name() ?: $actor->email(), (string) $actor->id());
        Toast::success(__('Payment records refreshed.'));

        return back();
    }

    public function order(Request $request, string $order, OrderView $view, Refunds $access, Registry $providers)
    {
        $origin = QueueContext::orderOrigin($request);
        $filters = [...$request->validate([...OrderListing::FILTERS, 'page' => 'nullable|integer|min:1']), ...$origin];
        $activity = $this->store->table('order_activity')->where('order_id', $order)->orderByDesc('id')
            ->simplePaginate(20, ['id', 'kind', 'message', 'author', 'created_at'], 'activity_page')
            ->appends($filters)->fragment('order-activity')
            ->through(fn ($entry) => [
                'id' => $entry->id, 'kind' => $entry->kind, 'message' => $entry->message, 'author' => $entry->author,
                'date' => Carbon::parse($entry->created_at)->locale(app()->getLocale())->isoFormat('lll'),
            ]);

        return Inertia::render('sellissimo::Order', [
            'order' => $view->detail(app(OrderListing::class)->forStaff([])->where('id', $order)->firstOrFail(), $this->store, $access, $providers),
            'csrfToken' => csrf_token(), 'listUrl' => $origin ? cp_route('sellissimo.'.$origin['origin'], $origin['queue']) : cp_route('sellissimo.orders', $filters),
            'listLabel' => match ($origin['origin'] ?? '') { 'drafts' => 'Draft orders', 'returns' => 'Return requests', default => 'Orders' },
            'activity' => $activity, 'noteRequestId' => (string) Str::uuid(),
            'noteUrl' => $request->user()->can('support sellissimo orders') ? cp_route('sellissimo.note', ['order' => $order, ...$filters]) : null,
        ]);
    }

    public function note(Request $request, string $order)
    {
        $input = $request->validate(['message' => 'required|string|max:2000', 'request_id' => 'required|uuid']);
        $origin = QueueContext::orderOrigin($request);
        $filters = [...$request->validate([...OrderListing::FILTERS, 'page' => 'nullable|integer|min:1']), ...$origin];
        $user = User::fromUser($request->user());
        $authorId = (string) $user->id();
        $this->store->db()->transaction(function () use ($order, $input, $user, $authorId) {
            $this->store->table('orders')->where('id', $order)->lockForUpdate()->firstOrFail();
            $existing = $this->store->table('order_activity')->where('order_id', $order)->where('request_id', $input['request_id'])->first();
            if ($existing) {
                abort_unless($existing->message === $input['message'] && $existing->author_id === $authorId, 409);

                return;
            }
            $this->store->recordActivity($order, 'note', $input['message'], $user->name() ?: $user->email(), $authorId, $input['request_id']);
        }, 3);
        Toast::success(__('Private note added.'));

        return redirect(cp_route('sellissimo.order', ['order' => $order, ...$filters]));
    }

    public function address(Request $request, string $order, \Sellissimo\Core\DeliveryAddress $addresses)
    {
        $input = CheckoutInput::validate($request);
        $data = $request->validate(['previous' => 'required|string|size:64', ...collect(\Sellissimo\Core\DeliveryFields::required($input['shipping_address'] ?? []))->mapWithKeys(fn ($field) => ['shipping_address.'.$field => 'required'])->all()]);
        if ($addresses->correct($order, $input['shipping_address'], $data['previous'], $request->user())) {
            Toast::info(__('Delivery address saved. Review the delivery charge and tax for this destination.'));
        } else {
            Toast::success(__('Delivery address saved.'));
        }
        return back();
    }

    public function labels(Request $request, string $order, \Sellissimo\Core\CarrierLabels $labels)
    {
        $data = $request->validate(['action' => 'required|in:book,void', 'request_id' => 'required|uuid', 'origin' => 'required_if:action,book|string|max:100', 'items' => 'required_if:action,book|array|max:100', 'items.*' => 'integer|min:0|max:999']);
        if ($data['action'] === 'void') {
            $labels->cancel($order, $data['request_id'], $request->user());
        } else {
            $labels->book($order, $data, $request->user());
        }
        return back();
    }

    public function reassign(Request $request, string $order, \Sellissimo\Core\ParcelLocations $locations)
    {
        $input = $request->validate(['request_id' => 'required|uuid', 'sku' => 'required|string|max:100',
            'from' => 'required|string|max:40', 'to' => 'required|string|max:40|different:from', 'quantity' => 'required|integer|min:1|max:999999999']);
        $locations->reassign($order, $input, User::fromUser($request->user()));
        return back();
    }

    public function ship(Request $request, string $order, Fulfillment $fulfillment, ShipmentEmails $emails)
    {
        $input = $request->validate(['tracking' => 'required|string|max:250', 'tracking_url' => 'nullable|url:https|max:4096', 'origin' => 'nullable|string|max:40', 'notify' => 'sometimes|boolean',
            'request_id' => 'nullable|uuid', 'items' => 'sometimes|array|min:1|max:100', 'items.*' => 'required|integer|min:0|max:999']);
        $parcel = $fulfillment->ship($order, $input, User::fromUser($request->user()));
        if ($request->boolean('notify')) {
            $emails->dispatch($parcel);
        }
        Toast::success(__('Shipment recorded.'));

        return back();
    }

    public function delivered(Request $request, string $order)
    {
        $id = $request->validate(['parcel' => 'required|uuid'])['parcel'];
        app(Fulfillment::class)->confirmDelivery($order, $id, now()->toDateTimeString(), $request->user()->email());

        return back();
    }

    public function receive(Request $request, string $order, Fulfillment $fulfillment)
    {
        $input = $request->validate(['request_id' => 'required|uuid', 'line_key' => 'required|string|max:100',
            'quantity' => 'required|integer|min:1|max:999', 'restocked' => 'required|integer|min:0|max:999', 'location' => 'nullable|string|max:40']);
        $fulfillment->receive($order, $input, User::fromUser($request->user()));
        Toast::success(__('Return recorded.'));

        return back();
    }

    public function shipmentEmail(Request $request, string $order, ShipmentEmails $emails)
    {
        $input = $request->validate(['parcel' => 'sometimes|uuid']);
        $query = $this->store->table('parcels')->where('order_id', $order);
        $id = isset($input['parcel']) ? $query->where('id', $input['parcel'])->value('id') : $query->latest('number')->value('id');
        abort_unless($id, 422);
        $this->store->table('parcels')->where('id', $id)->whereNull('queued_at')->update(['queued_at' => now()]);
        $emails->dispatch($id);

        return back();
    }

    public function cancel(Request $request, string $order, \Sellissimo\Core\Cancellations $cancellations)
    {
        $retry = $request->routeIs('statamic.cp.sellissimo.cancel.retry');
        $data = $request->validate([
            'request_id' => 'required|uuid', 'reason' => $retry ? 'nullable' : 'required|string|max:500',
            'restock' => $retry ? 'sometimes|boolean' : 'required|boolean',
            'notify' => $retry ? 'sometimes|boolean' : 'required|boolean', 'reference' => 'nullable|string|max:150',
        ]);
        $cancellations->cancel($order, $data, $request->user(), $retry);
        Toast::success($retry ? __('Refund request processed.') : __('Order cancelled.'));
        return back();
    }

    public function settleDelivery(Request $request, string $order)
    {
        $action = $request->validate(['action' => 'required|in:collect,cancel'])['action'];
        $this->checkout->settleDelivery($order, $action === 'collect');
        Toast::success($action === 'collect' ? __('Payment recorded.') : __('Order cancelled and stock released.'));

        return back();
    }

    public function refund(Request $request, string $order, Refunds $refunds)
    {
        if ($request->filled('display_amount') && $request->input('sku') !== '@all') {
            $digits = Money::fractionDigits($this->checkout->order($order)->currency);
            $decimal = $digits ? '(?:\.([0-9]{1,'.$digits.'}))?' : '';
            $text = $request->validate(['display_amount' => 'required|string|max:18'])['display_amount'];
            if (! preg_match('/^([0-9]{1,13})'.$decimal.'$/D', $text, $parts)) {
                throw ValidationException::withMessages(['display_amount' => 'Enter an amount in the order currency with the correct number of decimal places.']);
            }
            $request->merge(['amount' => (int) ($parts[1].str_pad($parts[2] ?? '', $digits, '0'))]);
        }
        $input = $request->validate(['request_id' => 'required|uuid', 'sku' => 'required|string|max:100', 'amount' => 'required_unless:sku,@all|integer|min:1']);
        $manual = null;
        if ($request->boolean('manual')) {
            $manual = [...$request->validate(['reason' => 'required|string|max:250', 'reference' => 'required|string|max:150']), 'author' => $request->user()->email()];
        }
        $refunds->request($order, $input['request_id'], $input['sku'], (int) ($input['amount'] ?? 0), $manual);

        $status = $this->store->table('refunds')->where('id', $input['request_id'])->value('status');
        match ($status) {
            'succeeded' => Toast::success($manual ? __('Manual refund recorded.') : __('Refund issued.')),
            'failed' => Toast::error(__('The payment provider declined the refund.')),
            default => Toast::info(__('Refund submitted. Waiting for the payment provider.')),
        };

        return back();
    }

    public function downloadAccess(Request $request, string $order)
    {
        $input = $request->validate(['sku' => 'required|string|max:100', 'blocked' => 'required|boolean']);
        $receipt = json_decode($this->checkout->order($order)->receipt, true);
        $line = LineOptions::find($receipt['lines'], $input['sku']);
        abort_unless($line && ($line['downloadable'] ?? $line['digital']), 422);
        $key = ['order_id' => $order, 'sku' => $input['sku']];
        $blocks = $this->store->table('download_blocks');
        if ($request->boolean('blocked')) {
            $blocks->insertOrIgnore($key);
        } else {
            $blocks->where($key)->delete();
        }

        Toast::success(__('Download access updated.'));

        return back();
    }

    public function resend(string $order, Receipts $receipts)
    {
        $this->store->table('orders')->where('id', $order)->where('status', 'paid')->update(['emailed_at' => null]);
        $receipts->send($order);
        Toast::success(__('Receipt sent.'));

        return back();
    }
}
