<?php

namespace Sellissimo\Core\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Sellissimo\Core\Amendments;
use Sellissimo\Core\Checkout;
use Sellissimo\Core\Contracts\PaymentUnavailable;
use Sellissimo\Core\CommerceFields;
use Sellissimo\Core\Money;
use Sellissimo\Core\OrderNumber;
use Sellissimo\Core\Pricing;
use Sellissimo\Core\Refunds;
use Sellissimo\Core\Store;
use Sellissimo\Core\StoreMail;
use Statamic\Facades\CP\Toast;

class AmendmentController
{
    public function change(string $order, Request $request, Amendments $changes, Checkout $checkout)
    {
        $delivery = $request->routeIs('statamic.cp.sellissimo.amend.delivery');
        $preview = $delivery || $request->routeIs('statamic.cp.sellissimo.amend.preview');
        $data = $request->validate(['items' => 'required|array|min:1|max:100', 'items.*.sku' => 'required|string|max:100', 'items.*.key' => 'nullable|string|max:100',
            'items.*.quantity' => 'required|integer|between:1,999', 'items.*.options' => 'sometimes|array|max:12', 'items.*.line_fields' => 'sometimes|array|max:100',
            'gateway' => 'nullable|string|max:80', 'coupon' => 'sometimes|nullable|string|max:80', 'shipping' => 'nullable|string|max:100',
            'shipping_address' => 'sometimes|array:'.implode(',', CheckoutInput::ADDRESS_FIELDS),
            'shipping_address.*' => 'nullable|string|max:250', 'shipping_address.country' => 'required_with:shipping_address|size:2',
            'version' => 'required|string|size:64', 'request_id' => ($preview ? 'sometimes' : 'required').'|uuid',
            'digest' => ($preview ? 'nullable' : 'required').'|string|size:64', 'reason' => ($preview ? 'nullable' : 'required').'|string|max:240',
            'refund_reference' => 'nullable|string|max:150']);
        $parent = $checkout->order($order);
        $currentCode = json_decode($parent->receipt, true)['code'] ?? '';
        abort_if(filled($data['coupon'] ?? null) && $data['coupon'] !== $currentCode, 422, 'Keep the original coupon or remove it.');
        if (isset($data['shipping_address'])) {
            $data['shipping_address'] = \Sellissimo\Core\PostalRegions::address($data['shipping_address'], 'shipping_address.region');
        }
        if (array_key_exists('coupon', $data)) {
            $data['coupon'] ??= '';
        }
        if ($delivery) {
            $quote = $changes->propose($parent, $data, deliveryPreview: true);

            return response()->json([
                'required' => collect($quote['lines'])->contains(fn ($line) => ! $line['digital']),
                'rates' => collect($quote['options'])->map(fn ($rate, $id) => [
                    'id' => $id, 'label' => $rate['label'], 'price' => Money::display($rate['amount'], $parent->currency),
                ])->values(),
            ]);
        }
        if ($preview) {
            [$after,$difference] = $changes->propose($parent, $data);

            return response()->json(['gateways' => $changes->balanceGateways($after, $difference, $parent->site), 'digest' => Pricing::fingerprint($after), 'difference' => $difference, 'balance' => Money::display(abs($difference), $parent->currency),
                'charges' => \Sellissimo\Core\OrderCharges::display($after), 'total' => Money::display($after['total'], $parent->currency), 'shipping' => Money::display($after['shipping']['amount'] ?? 0, $parent->currency), 'tax' => Money::display($after['tax'], $parent->currency)]);
        }
        $row = $changes->save($order, $data, $request->user()->email());
        Toast::success($row->status === 'applied' ? 'Order updated.' : 'Amendment saved.');

        return redirect(cp_route('sellissimo.order', $order));
    }

    public function artwork(string $order, Request $request, CommerceFields $fields, Store $store, Checkout $checkout)
    {
        $parent = $checkout->order($order);
        abort_unless($parent->status === 'paid' && $parent->tracking === null && ! app(Amendments::class)->pending($order), 409);
        $data = $request->validate(['handle' => 'required|alpha_dash|max:100', 'file' => 'required|file|max:10240']);
        $cart = $store->table('carts')->where('id', $parent->cart_id)->firstOrFail();
        $file = $fields->upload(CommerceFields::scope($cart), 'line', $data['handle'], $data['file'], true);

        return response()->json(['id' => $file->id, 'name' => $file->name], 201);
    }

    public function pay(string $amendment, Request $request, Amendments $changes, Checkout $checkout)
    {
        $row = $changes->row($amendment);
        $order = $checkout->order($row->order_id);
        abort_unless(\Sellissimo\Core\OrderLinks::valid($request, $order), 404);
        PrivateResponse::forSite($order->site);
        if ($row->status === 'applied') {
            return redirect(Checkout::link($order));
        }
        if ($row->payment_start_state === 'rejected') {
            return redirect(Checkout::link($order))->withErrors(['payment' => __('sellissimo::shop.payment_start_rejected')]);
        }
        abort_unless($row->status === 'awaiting_payment', 410);
        if ($request->isMethod('get')) {
            return view('sellissimo::amend-payment', ['number' => OrderNumber::display($order), 'reason' => $row->reason,
                'total' => Money::display($row->difference, $order->currency), 'url' => $request->fullUrl(),
                'receipt' => json_decode($row->proposed, true)]);
        }
        try {
            $row = $changes->reconcile($amendment, true);
            if ($row->status === 'awaiting_payment' && !$row->checkout_url) {
                throw new PaymentUnavailable('Awaiting confirmation for amendment '.$amendment.'.');
            }
        } catch (PaymentUnavailable $e) {
            report($e);
            return response()->view('sellissimo::payment-unavailable', [
                'retryUrl' => $request->fullUrl(), 'receiptUrl' => Checkout::link($order),
            ], 503, ['Retry-After' => '300']);
        }
        if ($row->payment_start_state === 'rejected') {
            return redirect(Checkout::link($order))->withErrors(['payment' => __('sellissimo::shop.payment_start_rejected')]);
        }
        abort_if($row->status === 'expired', 410);

        return $row->status === 'applied' ? redirect(Checkout::link($order)) : redirect()->away($row->checkout_url);
    }

    public function email(string $amendment, Amendments $changes, Checkout $checkout)
    {
        $row = $changes->row($amendment);
        abort_unless($row->status === 'awaiting_payment', 409);
        $order = $checkout->order($row->order_id);
        app(StoreMail::class)->send('amendment', $order->site, $order->email, self::link($row), [
            'order' => $order, 'payableBalance' => ['amount' => (int) $row->difference, 'currency' => $order->currency],
        ]);
        Toast::success(__('Payment link sent.'));

        return back();
    }

    public static function link(object $row): string
    {
        return \Sellissimo\Core\OrderLinks::signed(app(Checkout::class)->order($row->order_id), 'sellissimo.amend.pay', Carbon::parse($row->created_at)->addDay(), ['amendment' => $row->id]);
    }

    public function retry(string $amendment, Request $request, Amendments $changes)
    {
        $data = $request->validate(['reference' => 'nullable|string|max:150']);
        $row = $changes->row($amendment);
        $manual = filled($data['reference'] ?? null)
            ? ['reference' => $data['reference'], 'reason' => $row->reason, 'author' => $request->user()->email()]
            : null;
        app(Refunds::class)->retryCredit($row, $manual);

        return back();
    }
}
