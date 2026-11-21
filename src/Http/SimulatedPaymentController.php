<?php

namespace Sellissimo\Core\Http;

use Illuminate\Http\Request;
use Sellissimo\Core\Amendments;
use Sellissimo\Core\Checkout;
use Sellissimo\Core\SimulatedPayments;
use Sellissimo\Core\Store;

class SimulatedPaymentController
{
    public function __invoke(Request $request, string $payment, Store $store)
    {
        abort_unless(SimulatedPayments::local(), 404);
        $row = $store->table('simulated_payments')->where('id', $payment)->firstOrFail();
        $order = $store->table('orders')->where('id', $payment)->where('gateway', 'simulator')->first();
        $amendment = null;
        if (! $order) {
            $amendment = $store->table('amendments')->where('id', $payment)->where('gateway', 'simulator')->firstOrFail();
            $order = app(Checkout::class)->order($amendment->order_id);
        }
        PrivateResponse::forSite($order->site);
        if ($request->isMethod('get')) {
            return view('sellissimo::simulator', ['payment' => $row, 'order' => $order]);
        }
        $outcome = $request->validate(['outcome' => 'required|in:paid,declined,expired'])['outcome'];
        $query = $store->table('simulated_payments')->where('id', $payment);
        (clone $query)->where('status', 'pending')->where('expires_at', '>', now())->update(['status' => $outcome]);
        abort_unless($query->value('status') === $outcome, 409, __('sellissimo::shop.simulator_settled'));
        if ($amendment) {
            app(Amendments::class)->reconcile($payment);
        } else {
            app(Checkout::class)->reconcile($payment);
        }

        return redirect(Checkout::link($order));
    }
}
