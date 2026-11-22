<?php

namespace Sellissimo\Core\Tags;

use Illuminate\Support\Arr;
use Sellissimo\Core\AccountCarts;
use Sellissimo\Core\BuyerOrders;
use Sellissimo\Core\CartCurrency;
use Sellissimo\Core\Carts;
use Sellissimo\Core\Catalog;
use Sellissimo\Core\CatalogBrowse;
use Sellissimo\Core\CheckoutPolicy;
use Sellissimo\Core\Merchandising;
use Sellissimo\Core\Pricing;
use Sellissimo\Core\Registry;
use Statamic\Facades\Site;
use Statamic\Tags\Concerns\RendersForms;
use Statamic\Tags\Tags;

class Sellissimo extends Tags
{
    use RendersForms;

    public function addItem(): string
    {
        return $this->itemForm(route('statamic.sellissimo.form.add'), ['site' => $this->site(), 'sku' => $this->params->get('sku'), 'quantity' => $this->params->get('quantity')]);
    }

    public function updateItem(): string
    {
        $cart = app(AccountCarts::class)->restore(request(), $this->site());
        if (! $cart) {
            return '';
        }

        return $this->itemForm(route('statamic.sellissimo.form.item', $cart['id']), [
            'sku' => $this->params->get('sku'), 'line_key' => $this->params->get('line_key'), 'quantity' => $this->params->get('quantity'),
        ]);
    }

    private function itemForm(string $url, array $defaults): string
    {
        $html = $this->formOpen($url, 'POST', ['site', 'sku', 'quantity', 'line_key']);
        foreach ($defaults as $name => $value) {
            if ($value !== null) {
                $html .= '<input type="hidden" name="'.e($name).'" value="'.e($value).'">';
            }
        }

        return $html.$this->parse().'</form>';
    }

    public function orders(): array
    {
        $as = $this->params->get('as');
        if (! $user = request()->user()) {
            return $as ? [$as => [], 'no_results' => true, 'next_page_url' => null, 'prev_page_url' => null] : [];
        }
        $page = app(BuyerOrders::class)->page((string) $user->getAuthIdentifier(), $this->site(),
            max(1, min(100, $this->params->int('limit', 25))), 'orders_page');
        $items = $page->getCollection()->map(fn ($order) => (array) $order)->all();

        return $as ? [$as => $items, 'no_results' => empty($items), 'next_page_url' => $page->nextPageUrl(), 'prev_page_url' => $page->previousPageUrl()] : $items;
    }

    public function products(): array
    {
        $products = app(Catalog::class)->products($this->site());

        return array_values(array_map(fn ($p) => Arr::except($p, ['file']), $products));
    }

    public function featuredProducts(): array
    {
        return $this->productList(app(Merchandising::class)->featured($this->site(), $this->params->int('limit', 4)));
    }

    public function popularProducts(): array
    {
        return $this->productList(app(Merchandising::class)->popular($this->site(), $this->params->int('limit', 4), $this->params->int('days', 90)));
    }

    private function productList(array $products): array
    {
        return $this->params->has('as') ? [$this->params->get('as') => $products, 'no_results' => empty($products)] : $products;
    }

    public function catalog(): array
    {
        $filters = CatalogBrowse::filters([...request()->query(), ...$this->params->all()]);
        [$page, $categories, $facets] = app(CatalogBrowse::class)->page($this->site(), $filters, groupProducts: true);
        $page->appends([...$filters, 'site' => $this->site()]);

        return [
            $this->params->get('as', 'products') => $page->items(), 'no_results' => $page->isEmpty(),
            'facets' => $facets, 'categories' => $categories->all(), 'total' => $page->total(),
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
            'prev_page_url' => $page->previousPageUrl(), 'next_page_url' => $page->nextPageUrl(),
        ];
    }

    public function quote(): string
    {
        $key = app(AccountCarts::class)->restore(request(), $this->site());

        return $key ? $this->itemForm(route('statamic.sellissimo.form.quote', $key['id']), []) : '';
    }

    public function checkout(): string
    {
        $quote = $this->cart();

        return isset($quote['quote_token']) ? $this->itemForm(route('statamic.sellissimo.form.checkout', $quote['id']), [
            '_sellissimo_reviewed' => 1, 'quote_token' => $quote['quote_token'], 'policy_version' => $quote['checkout_policy']['version'],
        ]) : '';
    }

    public function shippingOptions(): array
    {
        return collect($this->cart()['options'] ?? [])->map(fn ($option, $handle) => ['handle' => $handle, ...$option])->values()->all();
    }

    public function paymentGateways(): array
    {
        return array_map(fn ($handle) => ['handle' => $handle, 'label' => $handle === 'pay_on_delivery' ? __('sellissimo::shop.pay_on_delivery') : ucfirst(str_replace('_', ' ', $handle))], $this->cart()['gateways'] ?? []);
    }

    private function site(): string
    {
        return $this->params->get('site') ?? Site::current()->handle();
    }

    public function cart(): array
    {
        $key = app(AccountCarts::class)->restore(request(), $this->site());
        if (! $key) {
            return [];
        }
        $cart = app(Carts::class)->get($key['id'], $key['token'], request()->user()?->getAuthIdentifier());

        if (app(CartCurrency::class)->stale($cart)) {
            $change = app(CartCurrency::class)->preview($cart)['preview'];

            return ['id' => $cart->id, 'currency_change' => $change, 'rebuild_url' => route('statamic.sellissimo.form.currency', $cart->id),
                'lines' => [], 'options' => [], 'gateways' => []];
        }
        $cart->customer = request()->user()?->getAuthIdentifier();
        $receipt = app(Pricing::class)->calculate($cart, session('sellissimo.quote.'.$this->site(), []), cartPreview: true);

        return ['id' => $cart->id, ...Pricing::publicReceipt($receipt), 'quote_token' => Pricing::fingerprint($receipt),
            'checkout_policy' => CheckoutPolicy::forSite($this->site()),
            'gateways' => app(Registry::class)->availablePayments($receipt, $this->site())];
    }
}
