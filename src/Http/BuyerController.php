<?php

namespace Sellissimo\Core\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Sellissimo\Core\BuyerOrders;
use Sellissimo\Core\Checkout;
use Sellissimo\Core\Downloads;
use Sellissimo\Core\Fulfillment;
use Sellissimo\Core\LineOptions;
use Sellissimo\Core\OrderNumber;
use Sellissimo\Core\Pricing;
use Sellissimo\Core\Refunds;

class BuyerController
{
    public function __construct(private Checkout $checkout, private Refunds $refunds) {}

    private function authorized(Request $request, string $id): object
    {
        $order = $this->checkout->order($id);
        $user = $request->user()?->getAuthIdentifier();
        abort_unless(($user !== null && (string) $user === $order->customer) || \Sellissimo\Core\OrderLinks::valid($request, $order), 404);

        PrivateResponse::forSite($order->site);
        return $order;
    }

    public function receipt(Request $request, string $order)
    {
        try {
            $order = $this->authorized($request, $order);
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException|\Illuminate\Database\RecordNotFoundException $e) {
            if ($request->expectsJson()) {
                throw $e;
            }

            $site = URL::hasCorrectSignature($request) && $e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
                ? $this->checkout->order($order)->site : \Statamic\Facades\Site::current()->handle();
            PrivateResponse::forSite($site);

            return response()->view('sellissimo::recover-receipt', compact('site'), 404);
        }
        $receipt = json_decode($order->receipt, true);
        if (!empty($order->delivery_override)) {
            $receipt['shipping_address'] = \Sellissimo\Core\DeliveryAddress::current($order, $receipt);
        }
        $links = [];
        foreach ($receipt['lines'] as &$line) {
            if (($line['download_limit'] ?? 0) > 0) {
                $line['downloads_remaining'] = max(0, $line['download_limit'] - app(Downloads::class)->count($order->id, $line));
            }
            if ($this->refunds->downloadable($order, $line)) {
                $expires = min(now()->addHours(2)->timestamp, (int) $request->query('expires', PHP_INT_MAX));
                $links[$line['key'] ?? $line['sku']] = \Sellissimo\Core\OrderLinks::signed($order, 'sellissimo.download', now()->setTimestamp($expires), ['order' => $order->id, 'sku' => $line['key'] ?? $line['sku']]);
            }
        }
        unset($line);
        $shipments = app(Fulfillment::class)->parcels($order->id);
        $policies = app(\Sellissimo\Core\ReturnPolicy::class)->forOrder($order, $shipments);
        $parcels = $shipments
            ->map(fn ($p) => ['number' => (int) $p->number, 'tracking_status' => $p->tracking_status, 'tracking' => $p->tracking, 'tracking_url' => $p->tracking_url, 'items' => $p->items]);
        $expiry = min(now()->addDays(\Sellissimo\Core\OperationalSettings::accessDays($order))->timestamp, (int) $request->query('expires', PHP_INT_MAX));
        $returnsUrl = $parcels->isNotEmpty() ? \Sellissimo\Core\OrderLinks::signed($order, 'sellissimo.buyer.returns', now()->setTimestamp($expiry), ['order' => $order->id, 'from_receipt' => 1]) : null;
        $eligible = \Sellissimo\Core\PaymentRetry::eligible($order);
        $replacement = $eligible ? app(\Sellissimo\Core\PaymentRetry::class)->replacement($order) : null;
        $replacementUrl = null;
        if ($replacement) {
            $key = $request->session()->get('sellissimo.retries.'.$order->id);
            $owner = $replacement->customer !== null && $replacement->customer === (string) $request->user()?->getAuthIdentifier();
            $guest = $replacement->customer === null && ($key['id'] ?? null) === $replacement->cart_id
                && hash_equals($replacement->token_hash, hash('sha256', $key['token'] ?? ''));
            if ($owner || $guest) {
                $replacementUrl = \Sellissimo\Core\OrderLinks::signed($replacement, 'sellissimo.receipt', now()->setTimestamp($expiry), ['order' => $replacement->id]);
            }
        }
        $retryUrl = $eligible && !$replacement ? \Sellissimo\Core\OrderLinks::signed($order, 'sellissimo.payment.retry', now()->setTimestamp($expiry), ['order' => $order->id]) : null;
        $data = ['retry_placed' => (bool) $replacement, 'replacement_receipt_url' => $replacementUrl, 'retry_url' => $retryUrl, 'policies' => $policies, 'payment_summary' => $this->refunds->buyerSummary($order), 'returns_url' => $returnsUrl, 'parcels' => $parcels, 'id' => $order->id, 'number' => OrderNumber::display($order), 'status' => $order->status, 'tracking' => $order->tracking, 'payment_url' => $order->status === 'pending' ? $order->checkout_url : null,
            'payment_rejected' => $order->payment_start_state === 'rejected',
            'payment_session_pending' => $order->status === 'pending' && $order->gateway !== 'historical' && !$order->checkout_url, 'receipt' => Pricing::publicReceipt($receipt), 'downloads' => $links];

        return $request->expectsJson() ? response()->json($data) : view('sellissimo::receipt', [...$data, 'site' => $order->site, 'guest_order' => $order->customer === null, 'account_order' => $order->customer !== null && $order->customer === (string) $request->user()?->getAuthIdentifier()]);
    }

    public function retry(Request $request, string $order, \Sellissimo\Core\PaymentRetry $retry)
    {
        $order = $this->authorized($request, $order);
        $key = $retry->restore($request, $order);
        $url = \Sellissimo\Core\OperationalSettings::storefront($order->site) ? route('sellissimo.checkout.page', ['site' => $order->site, 'step' => 'review'])
            : (\Sellissimo\Core\StoreSettings::forSection($order->site, 'emails', 'recovery_storefront_url', config('sellissimo.recovery.storefront_urls.'.$order->site)) ?: \Statamic\Facades\Site::get($order->site)->absoluteUrl());

        return $request->expectsJson() ? response()->json([...$key, 'review_url' => $url]) : redirect()->to($url);
    }

    public function leaveRetry(Request $request, string $cart)
    {
        $site = $request->validate(['site' => 'required|string|max:100'])['site'];
        $current = $request->session()->get('sellissimo.cart.'.$site);
        abort_unless(isset($current['retry']) && $current['id'] === $cart, 404);
        \Sellissimo\Core\PaymentRetry::finish($request, $site);

        return to_route('sellissimo.cart.page', ['site' => $site]);
    }

    public function download(Request $request, string $order, string $sku)
    {
        $order = $this->authorized($request, $order);
        $line = LineOptions::find(json_decode($order->receipt, true)['lines'], $sku);
        abort_unless($line, 404);

        return app(Downloads::class)->serve($order, $line, $request->isMethod('HEAD'));
    }

    public function accountAccess(Request $request)
    {
        $data = $request->validate([
            'site' => ['sometimes', 'string', \Illuminate\Validation\Rule::in(\Statamic\Facades\Site::all()->keys()->all())],
            'next' => 'sometimes|string|in:product,checkout', 'sku' => 'required_if:next,product|regex:/^[A-Za-z0-9][A-Za-z0-9_.-]{0,99}$/D',
            'claim' => 'sometimes|string|max:36',
        ]);
        $site = $data['site'] ?? \Statamic\Facades\Site::current()->handle();
        PrivateResponse::forSite($site);
        $url = route('sellissimo.history', array_filter(['site' => $site, 'claim' => $data['claim'] ?? null]));
        if (\Sellissimo\Core\OperationalSettings::storefront($site)) {
            $url = match ($data['next'] ?? null) {
                'product' => route('sellissimo.product', ['site' => $site, 'sku' => $data['sku'], ...\Sellissimo\Core\CatalogBrowse::filters($request->query())]),
                'checkout' => route('sellissimo.checkout.page', ['site' => $site]),
                default => $url,
            };
        }
        if ($request->user()) {
            return redirect($url);
        }
        $request->session()->put('url.intended', $url);
        return view('sellissimo::account-access', ['url' => $url, 'accounts' => \Sellissimo\Core\OperationalSettings::accountUrls($site)]);
    }

    public function logout(Request $request)
    {
        $validated = $request->validate([
            'site' => ['sometimes', 'string', \Illuminate\Validation\Rule::in(\Statamic\Facades\Site::all()->keys()->all())],
        ]);
        $site = $validated['site'] ?? \Statamic\Facades\Site::current()->handle();
        \Illuminate\Support\Facades\Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return to_route(\Sellissimo\Core\OperationalSettings::storefront($site) ? 'sellissimo.shop' : 'sellissimo.account.access', ['site' => $site]);
    }

    public function history(Request $request)
    {
        if (!$request->user() && !$request->expectsJson()) {
            return to_route('sellissimo.account.access', $request->only('site', 'claim'));
        }
        abort_unless($user = $request->user(), 401);
        $data = $request->validate(['site' => 'sometimes|string|max:100', 'claim' => 'nullable|string|max:36']);
        $site = $data['site'] ?? \Statamic\Facades\Site::current()->handle();
        PrivateResponse::forSite($site);
        $orders = app(BuyerOrders::class)->page((string) $user->getAuthIdentifier(), $site);

        return $request->expectsJson() ? response()->json($orders) : view('sellissimo::account', compact('orders', 'site'));
    }
}
