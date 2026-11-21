<?php

namespace Sellissimo\Core\Http;

use Sellissimo\Core\StaffSites;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Sellissimo\Core\Carts;
use Sellissimo\Core\Catalog;
use Sellissimo\Core\Checkout;
use Sellissimo\Core\CheckoutSteps;
use Sellissimo\Core\DraftCustomers;
use Sellissimo\Core\LineOptions;
use Sellissimo\Core\Money;
use Sellissimo\Core\Pricing;
use Sellissimo\Core\Registry;
use Sellissimo\Core\StockHolds;
use Sellissimo\Core\Inventory;
use Sellissimo\Core\Store;
use Statamic\Facades\CP\Toast;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DraftController
{
    public function __construct(private Store $store, private Catalog $catalog, private Carts $carts, private Pricing $pricing) {}

    public function index(Request $request)
    {
        $filters = $request->validate(['q' => 'nullable|string|max:150', 'view' => 'nullable|in:open,ordered,cancelled', 'site' => OrderListing::FILTERS['site']]);
        $query = $this->store->table('drafts')->leftJoin('sellissimo_orders as o', 'o.cart_id', '=', 'sellissimo_drafts.cart_id')
            ->join('sellissimo_carts as c', 'c.id', '=', 'sellissimo_drafts.cart_id')
            ->whereIn('c.site', StaffSites::handles())
            ->select('sellissimo_drafts.*', 'o.id as order_id', 'c.items', 'c.site');
        if ($site = $filters['site'] ?? null) {
            $query->where('c.site', $site);
        }
        if (filled($filters['q'] ?? null)) {
            $term = '%'.trim($filters['q']).'%';
            $email = $query->getGrammar()->wrap('sellissimo_drafts.input->email');
            $name = $query->getGrammar()->wrap('sellissimo_drafts.input->address->name');
            $query->where(fn ($q) => $q->whereRaw("lower($email) like lower(?)", [$term])
                ->orWhereRaw("lower($name) like lower(?)", [$term]));
        }
        match ($filters['view'] ?? null) {
            'open' => $query->whereNull('o.id')->whereNull('cancelled_at'),
            'ordered' => $query->whereNotNull('o.id'),
            'cancelled' => $query->whereNull('o.id')->whereNotNull('cancelled_at'),
            default => null,
        };
        $rows = $query->orderByDesc('sellissimo_drafts.created_at')->orderBy('sellissimo_drafts.id')->paginate(25)->withQueryString();
        $queue = $rows->currentPage() > 1 ? [...$filters, 'page' => $rows->currentPage()] : $filters;
        $context = $queue ? ['queue' => $queue] : [];
        $rows->through(fn ($row) => [
            'id' => $row->id, 'email' => json_decode($row->input, true)['email'] ?? null, 'date' => Carbon::parse($row->created_at)->locale(app()->getLocale())->isoFormat('ll'),
            'name' => json_decode($row->input, true)['address']['name'] ?? null,
            'items' => collect(json_decode($row->items, true))->sum(fn ($item) => is_array($item) ? $item['qty'] : $item),
            'state' => $row->order_id ? 'Ordered' : ($row->cancelled_at ? 'Cancelled' : 'Draft'),
            'url' => $row->order_id ? cp_route('sellissimo.order', ['order' => $row->order_id, 'origin' => 'drafts', ...$context]) : cp_route('sellissimo.drafts.edit', ['draft' => $row->id, ...array_filter(['site' => $filters['site'] ?? null]), ...$context]),
        ]);

        return Inertia::render('sellissimo::Drafts', ['records' => $rows, 'sites' => StaffSites::allowed()->map->name(), 'filters' => $filters, 'listUrl' => cp_route('sellissimo.drafts'), 'createUrl' => cp_route('sellissimo.drafts.new', [...array_filter(['site' => $filters['site'] ?? null]), ...$context])]);
    }

    public function customers(Request $request, DraftCustomers $customers)
    {
        $input = $request->validate(['q' => 'nullable|string|min:2|max:150', 'site' => 'required|string|max:100']);
        $this->catalog->currency($input['site']);

        return response()->json($customers->search($input['site'], trim($input['q'] ?? '')));
    }

    public function edit(Request $request, ?string $draft = null)
    {
        $queue = QueueContext::read($request, 'drafts');
        $context = $queue ? ['queue' => $queue] : [];
        $row = $draft ? $this->draft($draft) : null;
        $cart = $row ? $this->cart($row) : null;
        if ($cart) {
            StaffSites::authorize($cart->site);
        }
        if ($cart && ($order = $this->store->table('orders')->where('cart_id', $cart->id)->value('id'))) {
            return redirect(cp_route('sellissimo.order', ['order' => $order, ...($context ? ['origin' => 'drafts', ...$context] : [])]));
        }
        $site = $cart->site ?? ($request->validate(['site' => 'sometimes|string|max:100'])['site'] ?? StaffSites::selected());
        $items = collect(json_decode($cart->items ?? '{}', true))->map(fn ($item, $key) => is_array($item) ? $item : ['sku' => (string) $key, 'qty' => $item, 'options' => (object) []])->values();
        $products = $this->catalog->products($site, false, $items->pluck('sku')->unique()->all());

        $stale = $cart && !$row->cancelled_at && app(\Sellissimo\Core\CartCurrency::class)->stale($cart);
        $currency = $cart->currency ?? $this->catalog->currency($site);
        $savedInput = $row ? json_decode($row->input, true) : [];
        $today = now()->toDateString();
        $coupons = collect(config('sellissimo.discounts', []))->filter(fn ($rule) => isset($rule['rule']) || isset($rule['basis_points']) || isset($rule['amounts'][$currency]))
            ->keys()
            ->merge($this->store->table('discounts')->where('automatic', false)->where('enabled', true)->where('currency', $currency)
                ->where(fn ($q) => $q->whereNull('site')->orWhere('site', $site))
                ->where(fn ($q) => $q->whereNull('starts_on')->orWhere('starts_on', '<=', $today))
                ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $today))
                ->pluck('code'))->map(fn ($code) => (string) $code)->unique()->sort();

        return Inertia::render('sellissimo::DraftEditor', [
            'draft' => $row ? ['id' => $draft, 'reserveUntil' => $row->reserve_until && Carbon::parse($row->reserve_until)->isFuture() ? Carbon::parse($row->reserve_until)->toIso8601String() : null, 'revision' => $row->revision, 'cancelled' => (bool) $row->cancelled_at, 'input' => $savedInput, 'customRate' => isset($savedInput['custom_shipping']) ? Money::decimal($savedInput['custom_shipping']['amount'], $currency) : '',
                'sentAt' => $row->link_sent_at, 'link' => $row->cancelled_at || !$row->payable ? null : URL::temporarySignedRoute('sellissimo.draft.pay', now()->addDays(7), ['draft' => $draft, 'revision' => $row->revision]),
                'items' => $items->map(fn ($item) => [...$item, 'unit_price' => isset($item['staff_price']) ? Money::decimal($item['staff_price'], $currency) : null])] : null,
            'creationId' => $row ? null : ($creation = (string) Str::uuid()),
            'orderFields' => app(\Sellissimo\Core\CommerceFields::class)->controls('order', true), 'lineFields' => app(\Sellissimo\Core\CommerceFields::class)->controls('line', true),
            'uploadUrl' => cp_route('sellissimo.drafts.upload', $row ? $draft : $creation),
            'products' => collect($products)->map(fn ($product) => Arr::only($product, ['sku', 'title', 'option_fields', 'digital']))->values(),
            'catalogUrl' => cp_route('sellissimo.drafts.products', ['site' => $site]),
            'currencyChange' => $stale ? app(\Sellissimo\Core\CartCurrency::class)->preview($cart)['preview'] : null,
            'rebuildUrl' => $stale ? cp_route('sellissimo.drafts.currency', ['draft' => $draft, ...$context]) : null,
            'customersUrl' => cp_route('sellissimo.drafts.customers'), 'csrfToken' => csrf_token(), 'sites' => StaffSites::allowed()->filter(fn ($site) => \Sellissimo\Core\StoreSettings::forSection($site->handle(), 'currency', 'currency', config('sellissimo.currencies.'.$site->handle())))->map->name(), 'site' => $site,
            'countries' => StoreCountries::labels(), 'currency' => $currency, 'coupons' => $coupons->values(),
            'saveUrl' => cp_route('sellissimo.drafts.save', $context), 'quoteUrl' => cp_route('sellissimo.drafts.quote'), 'newUrl' => cp_route('sellissimo.drafts.new'),
            'cancelUrl' => $row ? cp_route('sellissimo.drafts.cancel', ['draft' => $draft, ...$context]) : null, 'listUrl' => cp_route('sellissimo.drafts', $queue ?: ($request->has('site') ? ['site' => $site] : [])),
        ]);
    }

    public function save(Request $request)
    {
        $quoteOnly = $request->routeIs('statamic.cp.sellissimo.drafts.quote');
        $intent = $request->validate(['intent' => 'sometimes|in:save,send'])['intent'] ?? null;
        $working = !$quoteOnly && $intent !== 'send';
        $send = !$quoteOnly && $intent === 'send';
        $data = $request->validate(['creation_id' => 'nullable|uuid', 'id' => 'nullable|uuid', 'revision' => 'required|integer|min:0', 'site' => 'required|string|max:100',
            'reserve_until' => 'sometimes|nullable|date|after:now', 'customer_id' => 'nullable|string|max:150', 'items' => ($working ? 'present|array' : 'required|array|min:1').'|max:100', 'items.*.sku' => 'nullable|required_without:items.*.service|string|max:100', 'items.*.service' => 'nullable|string|max:150',
            'items.*.unit_price' => 'nullable|required_with:items.*.service|string|max:18', 'items.*.qty' => 'required|integer|min:1|max:999', 'items.*.options' => 'nullable|array|max:12', 'items.*.line_fields' => 'nullable|array|max:100']);
        $id = $data['id'] ?? $data['creation_id'] ?? (string) Str::uuid();
        $row = ! empty($data['id']) ? $this->draft($id) : $this->store->table('drafts')->where('id', $id)->first();
        if ($row) {
            StaffSites::authorize($this->cart($row)->site);
            if (empty($data['id']) && ! $quoteOnly) {
                return redirect(cp_route('sellissimo.drafts.edit', ['draft' => $id, 'queue' => QueueContext::read($request, 'drafts')]));
            }
        }
        $input = CheckoutInput::validate($request, !$working && !$quoteOnly);
        if ($working && $request->boolean('custom_delivery') && (!$request->filled('delivery_label') || !$request->filled('delivery_charge'))) {
            $input['pending_delivery'] = $request->validate(['delivery_label' => 'nullable|string|max:120', 'delivery_charge' => 'nullable|string|max:20', 'pay_on_delivery' => 'sometimes|boolean']);
        } elseif ($request->boolean('custom_delivery')) {
            $rate = $request->validate(['delivery_label'=>'required|string|max:120','delivery_charge'=>'required|string|max:20', 'pay_on_delivery' => 'sometimes|boolean']);
            $currency = $this->catalog->currency($data['site']);
            $amount = Money::parseDecimal($rate['delivery_charge'], Money::fractionDigits($currency), 'delivery_charge');
            abort_if($amount > 99999999,422,'Delivery charge exceeds the supported maximum.');
            $input['custom_shipping'] = ['label'=>$rate['delivery_label'],'amount'=>$amount];
            if ($request->boolean('pay_on_delivery')) {
                $input['custom_shipping']['pay_on_delivery'] = true;
            }
            $input['shipping'] = 'draft.custom';
        }
        if ($customer = ($data['customer_id'] ?? null)) {
            $input['customer_id'] = app(DraftCustomers::class)->confirm($customer, $data['site'], $input['email'] ?? null);
        }
        app(StockHolds::class)->expireDue();
        $result = $this->store->db()->transaction(function () use ($data, $working, $quoteOnly, $intent, $input, $id, $request, $row) {
            if ($row) {
                $cart = $this->cart($row, true);
                $row = $this->draft($id, true);
                StaffSites::authorize($cart->site);
                $this->editable($row, $cart, (int) $data['revision']);
                abort_if(app(\Sellissimo\Core\CartCurrency::class)->stale($cart), 409, __('Review this draft in the new selling currency before editing.'));
                abort_unless($cart->site === $data['site'], 422);
            } else {
                $cart = (object) ['items' => '{}', 'site' => $data['site'], 'currency' => $this->catalog->currency($data['site'])];
            }
            $cart->field_scope = $id;
            $customFields = app(\Sellissimo\Core\CommerceFields::class);
            $input['order_fields'] = $customFields->values('order', $input['order_fields'] ?? [], $id, true, !$working && !$quoteOnly);
            $items = [];
            $products = $this->catalog->products($cart->site, false, array_column($data['items'], 'sku'));
            foreach ($data['items'] as $index => $item) {
                $service = $item['service'] ?? null;
                $sku = $service !== null ? '@SERVICE-'.strtoupper(substr(hash('sha256', $service), 0, 24)) : $item['sku'];
                abort_unless($service !== null || isset($products[$sku]), 422, 'Choose an available product.');
                $fields = $products[$sku]['option_fields'] ?? [];
                $terms = $service !== null ? ['service' => $service] : [];
                if (isset($item['unit_price'])) {
                    $terms['staff_price'] = Money::parseDecimal($item['unit_price'], Money::fractionDigits($cart->currency), "items.$index.unit_price");
                    abort_if($terms['staff_price'] > 99999999, 422, 'Unit price exceeds the supported maximum.');
                }
                if ($working) {
                    $fields = array_map(fn ($field) => [...$field, 'required' => false], $fields);
                }
                $values = LineOptions::validate($fields, $item['options'] ?? []);
                $custom = $customFields->values('line', $item['line_fields'] ?? [], $id, true, !$working && !$quoteOnly);
                $key = LineOptions::key($sku, $values, $custom);
                abort_if(isset($items[$key]), 422, 'Combine identical items into one quantity.');
                $items[$key] = ['sku' => $sku, 'qty' => (int) $item['qty'], 'options' => $values, 'line_fields' => $custom, ...$terms];
            }
            $cart->items = json_encode($items, JSON_THROW_ON_ERROR);
            $cart->customer = $input['customer_id'] ?? null;
            $quote = $working ? null : $this->pricing->calculate($cart, $input, !$quoteOnly);
            $paymentReady = $quote && (!$quote['total'] || app(Registry::class)->availablePayments($quote, $cart->site));
            if ($intent === 'send' && !$paymentReady) {
                throw \Illuminate\Validation\ValidationException::withMessages(['payment' => 'Enable a payment method for this order before sending a link.']);
            }
            if ($quoteOnly) {
                $money = fn ($amount) => Money::display($amount, $cart->currency);

                return ['payment_ready' => $paymentReady, 'ready' => $paymentReady && $quote['delivery_ready'] && !$quote['missing_billing_fields'] && !$quote['missing_shipping_fields'] && !$quote['stock_shortages'], 'shipping_tax' => isset($quote['shipping']['tax']) ? $money($quote['shipping']['tax']) : null, 'merchandise_tax' => $money(array_sum(array_column($quote['lines'], 'tax'))), 'missing_shipping_fields' => $quote['missing_shipping_fields'], 'billing_fields' => $quote['billing_fields'], 'subtotal' => $money($quote['subtotal']), 'tax' => $money($quote['tax']),
                    'charges' => \Sellissimo\Core\OrderCharges::display($quote), 'discount' => $money($quote['discount']), 'delivery_label' => $quote['shipping']['label'] ?? null, 'shipping' => $quote['shipping'] ? $money($quote['shipping']['amount'] - ($quote['shipping']['tax'] ?? 0)) : null,
                    'lines' => collect($quote['lines'])->map(fn ($line) => ['name' => $line['title'], 'qty' => $line['qty'], 'amount' => $money($line['subtotal'])]),
                    'missing_billing_fields' => $quote['missing_billing_fields'], 'stock_shortages' => $quote['stock_shortages'], 'promotion' => $quote['promotion'], 'promotions' => collect($quote['promotions'])->map(fn ($o) => [...$o, 'formatted' => Money::display($o['discount'], $cart->currency)]), 'methods' => collect($quote['options'])->map(fn ($method) => $method['label'].' · '.Money::display($method['amount'], $cart->currency)), 'total' => Money::display($quote['total'], $cart->currency)];
            }
            $payable = !$working;
            // Preserve links for complete drafts submitted by clients using the original save action.
            if ($working && $intent === null && filled($input['email'] ?? null)) {
                try {
                    $complete = $this->pricing->calculate($cart, $input, true);
                    $payable = !$complete['total'] || app(Registry::class)->availablePayments($complete, $cart->site);
                } catch (HttpException|\Illuminate\Validation\ValidationException $e) {
                    if ($e instanceof HttpException && !in_array($e->getStatusCode(), [409, 422], true)) {
                        throw $e;
                    }
                }
            }
            if (! $row) {
                $cart->id = $this->carts->create($cart->site)['id'];
                $inserted = $this->store->table('drafts')->insertOrIgnore(['id' => $id, 'payable' => $payable, 'cart_id' => $cart->id, 'input' => json_encode($input, JSON_THROW_ON_ERROR), 'created_at' => now()]);
                abort_unless($inserted, 409, 'This draft was saved by another request. Reopen it from the drafts list.');
            } else {
                $this->store->table('drafts')->where('id', $id)->update(['payable' => $payable, 'link_sent_at' => null, 'input' => json_encode($input, JSON_THROW_ON_ERROR), 'revision' => $row->revision + 1]);
            }
            $until = array_key_exists('reserve_until', $data) ? $data['reserve_until'] : ($row->reserve_until ?? null);
            $until = $until ? Carbon::parse($until)->timezone(config('app.timezone')) : null;
            $wanted = [];
            if ($until?->isFuture()) {
                foreach ($items as $item) {
                    $sku = $item['sku'];
                    if (isset($products[$sku]) && Inventory::tracked($products[$sku])) {
                        $wanted[$sku] = ($wanted[$sku] ?? 0) + $item['qty'];
                    }
                }
            }
            app(StockHolds::class)->saveDraft($row ?? (object) ['id' => $id], $wanted, $until, $request->user()->email());
            $this->store->table('carts')->where('id', $cart->id)->update(['items' => $cart->items, 'field_scope' => $id, 'order_fields' => json_encode($input['order_fields'], JSON_THROW_ON_ERROR)]);

            return $intent === 'send'
                ? ['amount' => $quote['amount_due'] ?? $quote['total'], 'currency' => $cart->currency]
                : null;
        }, 3);
        if ($quoteOnly) {
            return response()->json($result);
        }
        if ($send) {
            try {
                $this->store->db()->transaction(function () use ($id, $input, $data, $result) {
                    $row = $this->store->table('drafts')->where('id', $id)->lockForUpdate()->firstOrFail();
                    abort_unless($row->payable && !$row->cancelled_at && (int) $row->revision === (int) $data['revision'] + 1, 409);
                    $url = URL::temporarySignedRoute('sellissimo.draft.pay', now()->addDays(7), ['draft' => $id, 'revision' => $row->revision]);
                    app(\Sellissimo\Core\StoreMail::class)->send('draft', $data['site'], $input['email'], $url, ['draft' => $row, 'payableBalance' => $result]);
                    $this->store->table('drafts')->where('id', $id)->update(['link_sent_at' => now()]);
                });
                Toast::success(__('Payment link sent.'));
            } catch (\Symfony\Component\Mailer\Exception\TransportExceptionInterface $e) {
                report($e);
                Toast::error(__('Draft saved. Email delivery failed. Retry or copy the payment link.'));
            }
        } else {
            Toast::success(__('Draft saved.'));
        }

        return redirect(cp_route('sellissimo.drafts.edit', ['draft' => $id, 'queue' => QueueContext::read($request, 'drafts')]));
    }

    public function rebuild(Request $request, string $draft, \Sellissimo\Core\CartCurrency $currencies)
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'digest' => 'required|string|size:64']);
        $initial = $this->draft($draft);
        $this->store->db()->transaction(function () use ($initial, $draft, $currencies, $data) {
            $cart = $this->cart($initial, true);
            StaffSites::authorize($cart->site);
            $row = $this->draft($draft, true);
            $this->editable($row, $cart, (int) $data['revision']);
            $currencies->apply($cart, $data['digest'], true);
            $input = Arr::except(json_decode($row->input, true), ['discount', 'shipping', 'custom_shipping', 'pending_delivery', 'voucher', 'checkout_steps']);
            app(StockHolds::class)->saveDraft($row, [], null, null);
            $this->store->table('drafts')->where('id', $draft)->update(['input' => json_encode($input, JSON_THROW_ON_ERROR),
                'payable' => false, 'link_sent_at' => null, 'revision' => $row->revision + 1]);
        }, 3);
        return redirect(cp_route('sellissimo.drafts.edit', ['draft' => $draft, 'queue' => QueueContext::read($request, 'drafts')]));
    }

    public function cancel(Request $request, string $draft)
    {
        $revision = $request->validate(['revision' => 'required|integer|min:1'])['revision'];
        $initial = $this->draft($draft);
        $this->store->db()->transaction(function () use ($draft, $revision, $initial) {
            $cart = $this->cart($initial, true);
            StaffSites::authorize($cart->site);
            $row = $this->draft($draft, true);
            $this->editable($row, $cart, $revision);
            app(StockHolds::class)->saveDraft($row, [], null, null);
            $this->store->table('drafts')->where('id', $draft)->update(['cancelled_at' => now()]);
        }, 3);

        return redirect(cp_route('sellissimo.drafts', QueueContext::read($request, 'drafts')));
    }

    public function pay(Request $request, string $draft, Checkout $checkout, Registry $registry)
    {
        $row = $this->draft($draft);
        $cart = $this->cart($row);
        PrivateResponse::forSite($cart->site);
        $revision = (int) $request->query('revision');
        abort_unless($row->payable && ! $row->cancelled_at && $revision === (int) $row->revision, 410, __('sellissimo::shop.this_payment_link_has_been_replaced_or_cancelled'));
        $input = json_decode($row->input, true);
        $order = $this->store->table('orders')->where('cart_id', $row->cart_id)->first();
        $customer = $input['customer_id'] ?? null;
        $voucherKey = 'sellissimo.draft_voucher.'.$draft.'.'.$revision;
        $applyVoucher = !$order && $request->isMethod('post') && $request->input('action') === 'apply_voucher';
        if ($applyVoucher) {
            $voucher = $request->validate(['voucher' => 'nullable|string|max:80'])['voucher'] ?? null;
            if ($voucher) {
                app(\Sellissimo\Core\GiftVouchers::class)->byCode($voucher, $cart->site, $cart->currency);
            }
            $request->session()->put($voucherKey, $voucher);
        }
        $input['voucher'] = $request->session()->get($voucherKey);
        if (! $order && $customer) {
            try {
                app(DraftCustomers::class)->confirm($customer, $cart->site, $input['email'], selecting: false);
            } catch (\Illuminate\Validation\ValidationException $e) {
                abort(422, __('sellissimo::shop.the_customer_account_for_this_order_changed_ask_the_store_for_a_new_payment_link'));
            }
        }
        if (! $order && ($request->isMethod('get') || $applyVoucher)) {
            try {
                $cart->customer = $customer;
                $quote = Pricing::publicReceipt($this->pricing->calculate($cart, $input, true));
            } catch (ValidationException|HttpException $e) {
                if ($e instanceof ValidationException && isset($e->errors()['voucher'])) {
                    $request->session()->forget($voucherKey);
                    return redirect()->to($request->fullUrl())->withErrors($e->errors());
                }
                if ($request->expectsJson() || ($e instanceof HttpException && !in_array($e->getStatusCode(), [409, 422], true))) {
                    throw $e;
                }

                return response()->view('sellissimo::draft-unavailable', ['reason' => $e->getMessage()], 422);
            }

            $steps = app(CheckoutSteps::class)->definitions($quote, $cart->site);
            return view('sellissimo::draft-payment', ['site' => $cart->site, 'quote' => $quote, 'steps' => $steps, 'digest' => Pricing::fingerprint(Arr::except($quote, 'checkout_steps')), 'url' => $request->fullUrl(), 'gateways' => $registry->availablePayments($quote, $cart->site), 'email' => $input['email']]);
        }
        if ($order && $request->isMethod('get')) {
            if ($order->status === 'pending' && ! $order->checkout_url) {
                return view('sellissimo::resume-payment', ['url' => $request->fullUrl()]);
            }

            return redirect()->away($order->status === 'pending' ? $order->checkout_url : Checkout::link($order));
        }
        // A retried request can also resume a previously interrupted payment session.
        $buyer = $request->validate(['gateway' => 'nullable|string|max:80', 'checkout_steps' => 'sometimes|array|max:40',
            'checkout_steps.*' => 'array|max:40', 'checkout_steps.*.*' => 'nullable|string|max:500']);
        $gateway = $buyer['gateway'] ?? '';
        if (isset($buyer['checkout_steps'])) {
            $input['checkout_steps'] = $buyer['checkout_steps'];
        }
        try {
            $order = $checkout->begin($cart, [...$input, 'gateway' => $gateway], $customer, function ($quote) use ($request, $draft, $revision) {
                $latest = $this->draft($draft);
                abort_unless($latest->payable && ! $latest->cancelled_at && (int) $latest->revision === $revision, 410);
                abort_unless(hash_equals(Pricing::fingerprint(Arr::except($quote, 'checkout_steps')), (string) $request->input('digest')), 409, 'Prices or delivery changed. Review your order again before paying.');
            });
        } catch (HttpException $e) {
            if ($request->expectsJson() || ! in_array($e->getStatusCode(), [409, 422], true)) {
                throw $e;
            }

            return redirect()->to($request->fullUrl())->withErrors(['draft' => $e->getMessage()]);
        }

        return redirect()->away($order->status === 'pending' && $order->checkout_url ? $order->checkout_url : Checkout::link($order));
    }

    private function editable(object $draft, object $cart, int $revision): void
    {
        abort_unless(! $draft->cancelled_at && (int) $draft->revision === $revision && ! $this->store->table('orders')->where('cart_id', $cart->id)->lockForUpdate()->first(['id']), 409, 'This draft changed or checkout has started. Reopen the drafts list.');
    }

    private function draft(string $id, bool $lock = false): object
    {
        return $this->store->table('drafts')->where('id', $id)->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
    }

    private function cart(object $draft, bool $lock = false): object
    {
        return $this->store->table('carts')->where('id', $draft->cart_id)->when($lock, fn ($query) => $query->lockForUpdate())->firstOrFail();
    }
}
