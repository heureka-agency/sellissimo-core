<?php

namespace Sellissimo\Core\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Sellissimo\Core\AccountCarts;
use Sellissimo\Core\AddressBooks;
use Sellissimo\Core\CartCurrency;
use Sellissimo\Core\CartRecovery;
use Sellissimo\Core\Carts;
use Sellissimo\Core\Catalog;
use Sellissimo\Core\CatalogBrowse;
use Sellissimo\Core\Checkout;
use Sellissimo\Core\CheckoutCountries;
use Sellissimo\Core\CheckoutPolicy;
use Sellissimo\Core\CheckoutSteps;
use Sellissimo\Core\CommerceFields;
use Sellissimo\Core\OperationalSettings;
use Sellissimo\Core\PaymentRetry;
use Sellissimo\Core\Pricing;
use Sellissimo\Core\ProductDescription;
use Sellissimo\Core\PurchasePrerequisites;
use Sellissimo\Core\Registry;
use Sellissimo\Core\ReturnPolicy;
use Sellissimo\Core\Store;
use Sellissimo\Core\StoreSettings;
use Sellissimo\Core\VariantChoices;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ShopController
{
    public function __construct(private Catalog $catalog, private Carts $carts, private AccountCarts $accounts, private Pricing $pricing, private Checkout $checkout, private Registry $providers) {}

    public function catalog(Request $request)
    {
        $site = $request->validate(['site' => 'sometimes|string|max:100'])['site'] ?? Site::current()->handle();
        PrivateResponse::forSite($site);
        $api = $request->routeIs('sellissimo.api.*');
        $fragment = ! $api && $request->header('X-Sellissimo-Fragment') === 'catalog';
        $filters = CatalogBrowse::filters($request->query());
        try {
            [$page, $categories, $facets] = app(CatalogBrowse::class)->page($site, $filters, groupProducts: ! $api);
        } catch (ValidationException $error) {
            $fields = array_keys($error->errors());
            if ($api || $fragment || array_diff($fields, ['price_min', 'price_max'])) {
                throw $error;
            }

            return to_route('sellissimo.shop', ['site' => $site, ...Arr::except($filters, [...$fields, 'page'])])
                ->withErrors($error->validator)->withInput(Arr::only($filters, $fields));
        }
        $products = $page->items();
        if ($api) {
            return response()->json(['products' => $products, 'facets' => $facets, 'pagination' => Arr::only($page->toArray(),
                ['current_page', 'per_page', 'total', 'last_page', 'prev_page_url', 'next_page_url'])]);
        }
        if ($page->currentPage() > $page->lastPage()) {
            return to_route('sellissimo.shop', ['site' => $site, ...$filters, 'page' => $page->lastPage()]);
        }
        $hasCatalogCriteria = ! empty($filters['in_stock']) || (bool) Arr::only($filters, ['q', 'category', 'price_min', 'price_max', 'attributes']);
        $listing = ['products' => $products, 'page' => $page, 'site' => $site, 'hasCatalogCriteria' => $hasCatalogCriteria,
            'filters' => $filters, 'categories' => $categories, 'facets' => $facets, 'currency' => $this->catalog->currency($site)];
        if ($fragment) {
            return view('sellissimo::catalog-response', $listing);
        }

        return view('sellissimo::shop', [...$this->cartPage($request, $site), ...$listing,
            'eligiblePrerequisites' => app(PurchasePrerequisites::class)->eligibleInCatalog($products, $site, $request->user()?->getAuthIdentifier()),
            'lineControls' => app(CommerceFields::class)->controls('line')]);
    }

    public function showCart(Request $request)
    {
        $site = $request->validate(['site' => 'sometimes|string|max:100'])['site'] ?? Site::current()->handle();
        PrivateResponse::forSite($site);

        return view('sellissimo::cart', $this->cartViewData($request, $site));
    }

    private function cartViewData(Request $request, string $site): array
    {
        return [
            ...$this->cartPage($request, $site),
            'filters' => CatalogBrowse::filters($request->query()),
            'lineControls' => app(CommerceFields::class)->controls('line'),
        ];
    }

    public function checkoutPage(Request $request, string $step = 'contact')
    {
        $site = $request->validate(['site' => 'sometimes|string|max:100'])['site'] ?? Site::current()->handle();
        PrivateResponse::forSite($site);
        if (! $request->user() && ! StoreSettings::forSection($site, 'checkout', 'guest_checkout', config('sellissimo.guest_checkout', true))) {
            return to_route('sellissimo.account.access', ['site' => $site, 'next' => 'checkout']);
        }
        $steps = app(CheckoutSteps::class);
        $order = $steps->order($site);
        abort_unless(in_array($step, $order, true), 404);
        $state = $this->cartPage($request, $site);
        if ($this->cartNeedsAttention($state)) {
            return to_route('sellissimo.cart.page', ['site' => $site]);
        }
        $definitions = $steps->definitions($state['receipt'], $site);
        $physical = isset($definitions['delivery']);
        if (! isset($definitions[$step])) {
            $next = collect(array_slice($order, array_search($step, $order, true) + 1))->first(fn ($handle) => isset($definitions[$handle]));

            return to_route('sellissimo.checkout.page', ['step' => $next, 'site' => $site]);
        }
        if ($needed = $steps->firstIncomplete($definitions, $state['receipt'], $state['contact'], $step)) {
            return to_route('sellissimo.checkout.page', ['step' => $needed, 'site' => $site]);
        }
        $savedAddresses = $step === 'contact' ? $this->savedAddresses($request, $site) : [];

        return view('sellissimo::checkout', [...$state, 'savedAddresses' => $savedAddresses, 'step' => $step, 'physical' => $physical, 'definitions' => $definitions]);
    }

    private function cartNeedsAttention(array $state): bool
    {
        return $state['unavailable'] || empty($state['receipt']['lines']) || ! empty($state['receipt']['stock_shortages']);
    }

    public function digitalBilling(Request $request, string $cart)
    {
        $cart = $this->cart($request, $cart);
        $site = $cart->site;
        $input = Arr::only(CheckoutInput::validate($request), ['address', 'email', 'discount']);
        $savedAddresses = $this->savedAddresses($request, $site);
        if ($id = $request->validate(['saved_address' => 'sometimes|uuid'])['saved_address'] ?? null) {
            $entry = collect($savedAddresses)->firstWhere('id', $id);
            abort_unless($entry, 404);
            $input['address'] = $entry['address'];
        }
        $quote = $this->pricing->calculate($cart, [...$request->session()->get('sellissimo.quote.'.$site, []), ...$input], cartPreview: true);
        abort_unless($quote['lines'] && collect($quote['lines'])->every(fn ($line) => $line['digital']), 422);

        return view('sellissimo::address-inputs', ['field' => 'address', 'address' => $quote['address'], 'onlyFields' => $quote['billing_fields'], 'step' => 'contact', 'site' => $site, 'savedAddresses' => $savedAddresses]);
    }

    private function savedAddresses(Request $request, string $site): array
    {
        $user = $request->user();

        return $user ? app(AddressBooks::class)->read(AddressBooks::scope($site, (string) $user->getAuthIdentifier()))['entries'] : [];
    }

    private function applySavedAddress(Request $request, object $cart, bool $physical)
    {
        $group = $request->input('use_saved_address');
        abort_unless($group === 'address' || ($physical && $group === 'shipping_address'), 422);
        $field = 'saved_addresses.'.$group;
        $selection = $request->input($field);
        $entry = is_string($selection) ? collect($this->savedAddresses($request, $cart->site))->firstWhere('id', $selection) : null;
        $purpose = $group === 'address' ? 'billing' : 'shipping';
        $countries = app(CheckoutCountries::class)->options($cart->site, $purpose);
        if (! $entry || ! isset($countries[$entry['address']['country'] ?? ''])) {
            throw ValidationException::withMessages([$field => __('sellissimo::shop.choose_available_address')]);
        }

        // Keep the other contact fields until the shopper explicitly continues checkout.
        $input = $request->only('email', 'recovery_consent', 'address', 'shipping_address', 'use_shipping_address',
            'shipping_instructions', 'discount', 'order_fields');
        $address = $entry['address'];
        if (! $physical) {
            $quote = $this->pricing->calculate($cart, [...$request->session()->get('sellissimo.quote.'.$cart->site, []), 'address' => $address], cartPreview: true);
            $request->session()->flash('sellissimo.billing_fields.'.$cart->site, $quote['billing_fields']);
            $address = Arr::only($address, ['country', ...$quote['billing_fields']]);
        }
        $input[$group] = $address;
        if ($group === 'shipping_address') {
            $input['use_shipping_address'] = '1';
        }

        return to_route('sellissimo.checkout.page', ['site' => $cart->site])->withInput($input);
    }

    private function activeStorefrontCart(Request $request, string $site, bool $create = false): ?array
    {
        $key = $this->accounts->restore($request, $site, $create);
        if (! $key || $request->user() || ! app(Store::class)->table('orders')
            ->where('site', $site)->where('cart_id', $key['id'])->exists()) {
            return $key;
        }

        // The guest API keeps the converted cart's credentials for idempotent requests.
        // A browsing request only reads them. A new purchase starts with a fresh quote.
        if (! $create) {
            return null;
        }
        $request->session()->forget(['sellissimo.quote.'.$site, 'sellissimo.billing_fields.'.$site]);

        return $this->carts->create($site);
    }

    private function cartPage(Request $request, string $site): array
    {
        $key = $this->activeStorefrontCart($request, $site);
        $receipt = null;
        $currencyChange = null;
        $notice = $request->session()->get('sellissimo.cart_notice');
        if ($key) {
            try {
                $savedCart = $this->carts->get($key['id'], $key['token'], $request->user()?->getAuthIdentifier());
                $savedQuote = $request->session()->get('sellissimo.quote.'.$site);
                if ($savedQuote === null) {
                    $savedQuote = $request->user() ? app(AddressBooks::class)->defaults($site, (string) $request->user()->getAuthIdentifier()) : [];
                }
                if (app(CartCurrency::class)->stale($savedCart)) {
                    $currencyChange = app(CartCurrency::class)->preview($savedCart)['preview'];
                } else {
                    $savedCart->customer = $request->user()?->getAuthIdentifier();
                    try {
                        $receipt = $this->pricing->calculate($savedCart, $savedQuote, cartPreview: true);
                    } catch (ValidationException $e) {
                        if (! isset($e->errors()['voucher'])) {
                            throw $e;
                        }
                        unset($savedQuote['voucher']);
                        $receipt = $this->pricing->calculate($savedCart, $savedQuote, cartPreview: true);
                        $request->session()->put('sellissimo.quote.'.$site, $savedQuote);
                        $notice = __('Review your order. The previously applied gift voucher is unavailable.');
                    } catch (HttpException $e) {
                        if ($e->getStatusCode() !== 422 || empty($savedQuote['discount'])) {
                            throw $e;
                        }
                        unset($savedQuote['discount']);
                        $receipt = $this->pricing->calculate($savedCart, $savedQuote, cartPreview: true);
                        $request->session()->put('sellissimo.quote.'.$site, $savedQuote);
                        $notice = __('sellissimo::shop.your_previous_discount_no_longer_applies_review_the_updated_total');
                    }
                }
            } catch (ValidationException|HttpException $e) {
                if (! in_array($e instanceof ValidationException ? 422 : $e->getStatusCode(), [404, 409, 422], true)) {
                    throw $e;
                }
                $notice = $e instanceof ValidationException ? $e->getMessage() : __('sellissimo::shop.your_saved_cart_is_no_longer_available_start_a_new_cart_with_current_prices');
            }
        }

        $unavailable = $receipt['unavailable'] ?? [];
        if ($unavailable) {
            $notice = __('sellissimo::shop.review_unavailable_lines');
        }

        return ['currencyChange' => $currencyChange, 'orderControls' => app(CommerceFields::class)->controls('order'), 'fieldValues' => json_decode($savedCart->order_fields ?? '{}', true),
            'contact' => $savedQuote['email'] ?? null, 'recoveryEmail' => $savedCart->recovery_email ?? null, 'unavailable' => $unavailable, 'notice' => $notice,
            'site' => $site, 'cart' => $key['id'] ?? null, 'receipt' => $receipt, 'gateways' => $receipt ? $this->providers->availablePayments($receipt, $site) : []];
    }

    public function product(Request $request, string $sku)
    {
        $site = $request->validate(['site' => 'sometimes|string|max:100'])['site'] ?? Site::current()->handle();
        PrivateResponse::forSite($site);
        $products = $this->catalog->browseProducts($site, [strtoupper($sku)], true);
        abort_unless($product = $products[strtoupper($sku)] ?? null, 404);
        unset($product['file']);
        $description = Entry::find($product['entry_id'])?->value('sellissimo_description');
        $product['description_html'] = ProductDescription::html($description);
        $variants = collect($products)->where('entry_id', $product['entry_id'])
            ->map(fn ($item) => Arr::only($item, ['sku', 'variant', 'attributes', 'price', 'available']))->values();
        if ($request->routeIs('sellissimo.api.*')) {
            return response()->json(['product' => $product, 'variants' => $variants, 'line_fields' => app(CommerceFields::class)->controls('line')]);
        }

        $lineControls = app(CommerceFields::class)->controls('line');
        $choices = VariantChoices::groups($variants, $product);
        $prerequisite = app(PurchasePrerequisites::class)->notice($product, $site, $request->user()?->getAuthIdentifier());
        $filters = CatalogBrowse::filters($request->query());
        $returnDays = $product['digital'] ? null : app(ReturnPolicy::class)->days($site);

        return view('sellissimo::product', compact('product', 'variants', 'site', 'lineControls', 'choices', 'prerequisite', 'returnDays', 'filters'));
    }

    public function addFromForm(Request $request)
    {
        $sku = $request->validate(['sku' => 'required|string|max:100'])['sku'];
        $request->mergeIfMissing(['quantity' => 1]);

        return $this->addProduct($request, $sku);
    }

    public function addProduct(Request $request, string $sku)
    {
        $filters = $request->routeIs('sellissimo.product.add') ? CatalogBrowse::filters($request->query()) : [];
        $data = $request->validate(['site' => 'required|string|max:100', 'quantity' => 'required|integer|min:1|max:999', 'options' => 'nullable|array|max:12']);
        $key = $this->activeStorefrontCart($request, $data['site'], true);
        $request->session()->put('sellissimo.cart.'.$data['site'], $key);
        $cart = $this->carts->get($key['id'], $key['token'], $request->user()?->getAuthIdentifier());
        $this->carts->set($cart, strtoupper($sku), (int) $data['quantity'], $data['options'] ?? [], increment: true, custom: $request->validate(['line_fields' => 'sometimes|array|max:100'])['line_fields'] ?? []);

        return $this->backToShop($request, $data['site'], $filters);
    }

    public function session()
    {
        return response()->json(['csrf_token' => csrf_token()]);
    }

    public function create(Request $request)
    {
        $site = $request->validate(['site' => 'sometimes|string|max:100'])['site'] ?? Site::current()->handle();
        PrivateResponse::forSite($site);
        $key = $request->user() ? $this->accounts->restore($request, $site, true) : $this->carts->create($site);
        $request->session()->put('sellissimo.cart.'.$site, $key);
        if ($request->routeIs('sellissimo.api.*')) {
            return response()->json($key, 201);
        }
        $request->session()->forget('sellissimo.quote.'.$site);

        return $this->backToShop($request, $site);
    }

    private function backToShop(Request $request, string $site, array $filters = [])
    {
        if (str_contains($request->route()->getName(), 'sellissimo.form.')) {
            return back();
        }

        if ($request->routeIs('sellissimo.item', 'sellissimo.cart.currency') || ($request->routeIs('sellissimo.product.add') && $request->input('return_to') === 'cart')) {
            return to_route('sellissimo.cart.page', ['site' => $site, ...$filters]);
        }

        return to_route('sellissimo.shop', ['site' => $site, ...$filters]);
    }

    private function cart(Request $request, string $id): object
    {
        $key = $request->bearerToken();
        if (! $request->routeIs('sellissimo.api.*')) {
            $saved = collect($request->session()->get('sellissimo.cart', []))->firstWhere('id', $id);
            $key = $saved['token'] ?? null;
        }

        $cart = $this->carts->get($id, $key, $request->user()?->getAuthIdentifier());
        PrivateResponse::forSite($cart->site);

        return $cart;
    }

    public function rebuild(Request $request, string $cart, CartCurrency $currencies, Store $store)
    {
        $cart = $this->cart($request, $cart);
        if ($request->isMethod('get')) {
            return response()->json($currencies->preview($cart)['preview']);
        }
        $digest = $request->validate(['digest' => 'required|string|size:64'])['digest'];
        $store->db()->transaction(function () use ($store, $cart, $digest, $currencies) {
            $locked = $store->table('carts')->where('id', $cart->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->customer === $cart->customer && hash_equals($locked->token_hash, $cart->token_hash), 409);
            $currencies->apply($locked, $digest);
        }, 3);
        $request->session()->forget('sellissimo.quote.'.$cart->site);
        $request->session()->flash('sellissimo.cart_notice', __('Review your new prices and delivery before placing the order.'));

        return $request->routeIs('sellissimo.api.*') ? response()->noContent() : $this->backToShop($request, $cart->site, $request->routeIs('sellissimo.cart.currency') ? CatalogBrowse::filters($request->query()) : []);
    }

    public function editItem(Request $request, string $cart, string $line)
    {
        $cart = $this->cart($request, $cart);
        abort_if(app(Store::class)->table('orders')->where('cart_id', $cart->id)->exists(), 409);
        $item = json_decode($cart->items, true)[$line] ?? null;
        abort_unless($item !== null, 404);
        $sku = is_array($item) ? $item['sku'] : $line;
        $product = $this->catalog->products($cart->site, false, [$sku])[$sku] ?? null;
        abort_unless($product, 404);
        $fields = app(CommerceFields::class)->controls('line');
        $values = array_intersect_key(is_array($item) ? ($item['line_fields'] ?? []) : [], $fields);

        $uploads = array_intersect_key($values, array_filter($fields, fn ($field) => $field['type'] === 'assets'));
        $filenames = app(Store::class)->table('field_uploads')->where('scope', CommerceFields::scope($cart))
            ->whereIn('id', array_values($uploads))->pluck('name', 'id')->all();

        return view('sellissimo::edit-cart-item', ['filenames' => $filenames, 'cart' => $cart->id, 'site' => $cart->site, 'key' => $line,
            'product' => $product, 'fields' => $fields, 'values' => $values, 'filters' => CatalogBrowse::filters($request->query()),
            'options' => is_array($item) ? ($item['options'] ?? []) : [], 'quantity' => is_array($item) ? $item['qty'] : $item]);
    }

    public function item(Request $request, string $cart)
    {
        $filters = $request->routeIs('sellissimo.item') ? CatalogBrowse::filters($request->query()) : [];
        $data = $request->validate(['sku' => 'required|string|max:100', 'quantity' => 'required|integer|min:0|max:999', 'options' => 'sometimes|array|max:12', 'line_key' => 'nullable|string|max:100']);
        $cart = $this->cart($request, $cart);
        $this->carts->set($cart, strtoupper($data['sku']), (int) $data['quantity'], $data['options'] ?? [], $data['line_key'] ?? null, custom: $request->validate(['line_fields' => 'sometimes|array|max:100'])['line_fields'] ?? []);

        if ($request->routeIs('sellissimo.item') && $request->wantsJson()) {
            $data = $this->cartViewData($request, $cart->site);
            $count = array_sum(array_column($data['receipt']['lines'] ?? [], 'qty'));

            return response()->json([
                'html' => view('sellissimo::cart-content', $data)->render(),
                'cart_label' => __('sellissimo::shop.cart_count', ['count' => $count]),
            ]);
        }

        return $request->routeIs('sellissimo.api.*') ? response()->noContent() : $this->backToShop($request, $cart->site, $filters);
    }

    public function checkoutCodes(Request $request, string $cart, string $step)
    {
        $cart = $this->cart($request, $cart);
        $cart->customer = $request->user()?->getAuthIdentifier();
        abort_unless(in_array($step, app(CheckoutSteps::class)->order($cart->site), true), 404);
        $target = route('sellissimo.checkout.page', ['site' => $cart->site, 'step' => $step]);
        $sessionKey = 'sellissimo.quote.'.$cart->site;
        $saved = $request->session()->get($sessionKey) ?? ($request->user() ? app(AddressBooks::class)->defaults($cart->site, (string) $request->user()->getAuthIdentifier()) : []);
        if ($step === 'contact' && is_string($request->input('email')) && filter_var($request->input('email'), FILTER_VALIDATE_EMAIL)) {
            $saved['email'] = $request->input('email');
        }
        $data = $request->validate(['code_action' => 'required|in:apply,remove_voucher,remove_discount',
            'coupon_code' => 'nullable|string|max:80', 'voucher_code' => 'nullable|string|max:80']);
        $restore = $request->only(['email', 'address', 'shipping_address', 'use_shipping_address', 'shipping_instructions',
            'order_fields', 'shipping', 'checkout_steps', 'gateway', 'consents', 'policy_version', 'recovery_consent']);
        if ($data['code_action'] === 'apply') {
            $saved['discount'] = trim($data['coupon_code'] ?? '');
            if (filled($data['voucher_code'] ?? null)) {
                $saved['voucher'] = $data['voucher_code'];
            }
        } else {
            unset($saved[$data['code_action'] === 'remove_discount' ? 'discount' : 'voucher']);
        }

        try {
            $this->pricing->calculate($cart, $saved, cartPreview: true);
        } catch (HttpException|ValidationException $error) {
            if ($error instanceof HttpException && $error->getStatusCode() !== 422) {
                throw $error;
            }
            $errors = $error instanceof ValidationException ? $error->errors() : ['discount' => [__($error->getMessage())]];
            if ($request->expectsJson()) {
                return response()->json(['errors' => $errors], 422);
            }

            return redirect($target)->withErrors($errors)->withInput([...$restore, 'coupon_code' => $data['coupon_code'] ?? '']);
        }
        $request->session()->put($sessionKey, $saved);

        return $request->expectsJson() ? response()->json(['url' => $target]) : redirect($target)->withInput($restore);
    }

    public function quote(Request $request, string $cart)
    {
        $cart = $this->cart($request, $cart);
        $cart->customer = $request->user()?->getAuthIdentifier();
        $steps = app(CheckoutSteps::class);
        $order = $steps->order($cart->site);
        $step = $request->routeIs('sellissimo.quote') ? ($request->validate(['checkout_step' => ['sometimes', Rule::in(array_slice($order, 0, -1))]])['checkout_step'] ?? null) : null;
        if ($step) {
            $state = $this->cartPage($request, $cart->site);
            if ($this->cartNeedsAttention($state)) {
                return to_route('sellissimo.cart.page', ['site' => $cart->site]);
            }
            $definitions = $steps->definitions($state['receipt'], $cart->site);
            abort_unless(isset($definitions[$step]), 422);
            if ($needed = $steps->firstIncomplete($definitions, $state['receipt'], $state['contact'], $step)) {
                return to_route('sellissimo.checkout.page', ['step' => $needed, 'site' => $cart->site]);
            }
        }
        if ($step === 'contact' && $request->has('use_saved_address')) {
            return $this->applySavedAddress($request, $cart, isset($definitions['delivery']));
        }
        if (in_array($step, ['contact', 'delivery'], true) && ! $request->filled('voucher') && ! $request->boolean('remove_voucher')) {
            $request->merge(['voucher' => $request->session()->get('sellissimo.quote.'.$cart->site.'.voucher')]);
        }
        if ($step === 'contact' && ! $request->has('discount')) {
            $request->merge(['discount' => $request->session()->get('sellissimo.quote.'.$cart->site.'.discount')]);
        }
        if ($step === 'contact') {
            $request->validate(['email' => 'required|email|max:254', 'address.country' => 'required']);
            $request->request->remove('checkout_steps');
        } elseif ($step) {
            $saved = $request->session()->get('sellissimo.quote.'.$cart->site, []);
            // Upstream edits may change which later steps and options apply.
            $earlier = array_slice($order, 0, array_search($step, $order, true));
            $answers = array_intersect_key($saved['checkout_steps'] ?? [], array_flip($earlier));
            if ($step === 'delivery') {
                $request->replace([...$saved, ...$request->only('shipping', 'discount', 'voucher'), 'checkout_steps' => $answers]);
            } else {
                $answers[$step] = $request->input("checkout_steps.$step", []);
                $request->replace([...$saved, 'checkout_steps' => $answers]);
            }
        }
        if ((! $step || $step === 'contact') && $request->routeIs('sellissimo.quote') && ! $request->boolean('use_shipping_address')) {
            $request->request->remove('shipping_address');
        }
        $input = $this->checkoutInput($request, $cart, false);
        $receipt = Pricing::publicReceipt($this->pricing->calculate($cart, $input));
        if ($step && $receipt['stock_shortages']) {
            return to_route('sellissimo.cart.page', ['site' => $cart->site]);
        }
        if ($step === 'contact' && $errors = CheckoutInput::missingAddress($receipt, $request->boolean('use_shipping_address'))) {
            $request->session()->flash('sellissimo.billing_fields.'.$cart->site, $receipt['billing_fields']);
            throw ValidationException::withMessages($errors);
        }
        app(CartRecovery::class)->recordQuote($cart, $request);
        if ($request->routeIs('sellissimo.api.*')) {
            $policy = app(CheckoutCountries::class);
            $countries = [];
            if ($policy->settings($cart->site)) {
                foreach (['billing', 'shipping'] as $purpose) {
                    $options = $policy->options($cart->site, $purpose, true);
                    $default = $policy->settings($cart->site)['default_'.$purpose] ?? '';
                    $countries[$purpose] = ['options' => $options, 'default' => isset($options[$default]) ? $default : null];
                }
            }

            return response()->json([...$receipt, 'policy_links' => OperationalSettings::policyLinks($cart->site), 'guest_checkout' => (bool) StoreSettings::forSection($cart->site, 'checkout', 'guest_checkout', config('sellissimo.guest_checkout', true)), 'checkout_step_definitions' => $steps->publicDefinitions($steps->definitions($receipt, $cart->site)),
                ...($countries ? ['checkout_countries' => $countries] : []), 'order_field_definitions' => app(CommerceFields::class)->controls('order'), 'checkout_policy' => CheckoutPolicy::forSite($cart->site), 'quote_token' => Pricing::fingerprint($receipt), 'gateways' => $this->providers->availablePayments($receipt, $cart->site)]);
        }
        $request->session()->put('sellissimo.quote.'.$cart->site, $input);
        if ($step) {
            $definitions = $steps->definitions($receipt, $cart->site);
            $following = array_slice($order, array_search($step, $order, true) + 1);
            $next = collect($following)->first(fn ($handle) => isset($definitions[$handle]));
            $next = $steps->firstIncomplete($definitions, $receipt, $input['email'] ?? null, $next) ?? $next;

            return to_route('sellissimo.checkout.page', ['site' => $cart->site, 'step' => $next]);
        }

        return $this->backToShop($request, $cart->site);
    }

    private function checkoutInput(Request $request, object $cart, bool $checkout): array
    {
        $input = CheckoutInput::validate($request, $checkout);
        if (isset($input['order_fields'])) {
            $fields = app(CommerceFields::class);
            $input['order_fields'] = $fields->values('order', $input['order_fields'], $fields::scope($cart), false, $checkout);
            $internal = array_filter($fields->definitions('order', true), fn ($field) => $field['internal']);
            $stored = $input['order_fields'] + array_intersect_key(json_decode($cart->order_fields ?? '{}', true), $internal);
            if (! $checkout) {
                app(Store::class)->table('carts')->where('id', $cart->id)->update(['order_fields' => json_encode($stored, JSON_THROW_ON_ERROR)]);
            } else {
                $input['order_fields'] = $stored;
            }
        }

        return $input;
    }

    public function checkout(Request $request, string $cart)
    {
        $cart = $this->cart($request, $cart);
        if (($request->routeIs('sellissimo.checkout') && $request->input('checkout_step') === 'review')
            || ($request->routeIs('statamic.sellissimo.form.checkout') && $request->boolean('_sellissimo_reviewed'))) {
            // An already placed order can still resume through Checkout's idempotent path.
            if (! app(Store::class)->table('orders')->where('cart_id', $cart->id)->exists()
                && $this->cartNeedsAttention($this->cartPage($request, $cart->site))) {
                if ($request->routeIs('statamic.sellissimo.form.checkout')) {
                    return back()->withErrors(['cart' => __('sellissimo::shop.review_unavailable_lines')]);
                }

                return to_route('sellissimo.cart.page', ['site' => $cart->site]);
            }
            $saved = $request->session()->get('sellissimo.quote.'.$cart->site, []);
            $request->validate(['quote_token' => 'required|string|size:64']);
            $request->replace([...$saved, ...$request->only('gateway', 'quote_token', 'consents', 'policy_version')]);
        }
        $input = $this->checkoutInput($request, $cart, true);
        $order = $this->checkout->begin($cart, [...$input, 'gateway' => $input['gateway'] ?? ''], $request->user()?->getAuthIdentifier());
        $url = $order->status === 'pending' && $order->checkout_url ? $order->checkout_url : Checkout::link($order);
        if (isset($cart->retry_order_id) && $request->session()->get('sellissimo.cart.'.$cart->site.'.id') === $cart->id) {
            PaymentRetry::finish($request, $cart->site);
        } elseif (! $request->routeIs('sellissimo.api.*')) {
            $request->session()->forget(['sellissimo.cart.'.$cart->site, 'sellissimo.quote.'.$cart->site]);
        }
        if ($request->routeIs('sellissimo.api.*')) {
            $waiting = $order->status === 'pending' && ! $order->checkout_url;
            $result = ['order' => $order->id, 'status' => $order->status, 'url' => $url, 'receipt_url' => Checkout::link($order)];
            if ($order->payment_start_state === 'rejected') {
                return response()->json([...$result, 'payment_rejected' => true, 'message' => __('sellissimo::shop.payment_start_rejected')], 422);
            }

            return $waiting ? response()->json([...$result, 'payment_session_pending' => true], 202) : response()->json($result);
        }

        return redirect()->away($url);
    }
}
