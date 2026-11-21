<?php

namespace Sellissimo\Core\Http;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Sellissimo\Core\OrderNumber;
use Statamic\Facades\Site;
use Sellissimo\Core\Store;

class OrderClaimController
{
    public function request(Request $request, Store $store)
    {
        $scope = $request->validate(['site' => ['sometimes', 'string', Rule::in(Site::all()->keys()->all())]]);
        $site = $scope['site'] ?? Site::current()->handle();
        PrivateResponse::forSite($site);
        $data = $request->validate(['order' => 'required|string|max:36']);
        $user = $request->user();
        $order = OrderNumber::findForBuyer($store, $site, $user->email, $data['order'], true);
        if ($order && $order->customer === null) {
            $url = \Sellissimo\Core\OrderLinks::signed($order, 'sellissimo.claim', now()->addMinutes(30), ['order' => $order->id, 'account' => $user->getAuthIdentifier(), 'email' => hash('sha256', strtolower($order->email))]);
            app(\Sellissimo\Core\StoreMail::class)->send('claim', $order->site, $order->email, $url, ['order' => $order]);
        }
        $message = __('sellissimo::shop.if_the_order_matches_your_account_email_a_confirmation_link_will_arrive_shortly');

        return $request->expectsJson() ? response()->json(['message' => $message]) : back()->with('sellissimo.notice', $message);
    }

    public function invitation(Request $request, string $invitation, Store $store)
    {
        return $store->db()->transaction(function () use ($request, $invitation, $store) {
            $query = $store->table('claim_invitations')->where('id', $invitation)->where('expires_at', '>', now());
            $invite = $query->lockForUpdate()->firstOrFail();
            PrivateResponse::forSite($invite->site);
            $user = $request->user();
            $url = $request->fullUrl();
            if (! $user) {
                abort_if($request->isMethod('post'), 403);
                $request->session()->put('url.intended', $url);
                return view('sellissimo::invitation', ['email' => $invite->email, 'url' => $url,
                    'accounts' => \Sellissimo\Core\OperationalSettings::accountUrls($invite->site), 'orders' => null]);
            }
            abort_unless(mb_strtolower($user->email) === $invite->email, 403);
            $account = (string) $user->getAuthIdentifier();
            $history = route('sellissimo.history', ['site' => $invite->site]);
            if ($invite->claimed_by) {
                abort_unless($invite->claimed_by === $account, 403);
                return redirect($history);
            }
            $ids = json_decode($invite->order_ids, true, flags: JSON_THROW_ON_ERROR);
            $orders = $store->table('orders')->where('site', $invite->site)->whereNull('customer')
                ->whereRaw('lower(email) = ?', [$invite->email])->whereIn('id', $ids);
            if ($request->isMethod('get')) {
                return view('sellissimo::invitation', ['email' => $invite->email, 'orders' => $orders->get()->map(fn ($order) => \Sellissimo\Core\OrderNumber::display($order)), 'url' => $url]);
            }
            $orders->update(['customer' => $account]);
            $query->update(['claimed_by' => $account]);
            return redirect($history);
        }, 3);
    }

    public function confirm(Request $request, string $order, Store $store)
    {
        $user = $request->user();
        abort_unless((string) $user->getAuthIdentifier() === $request->query('account') && hash_equals(hash('sha256', strtolower($user->email)), $request->query('email', '')), 403);
        $claim = $store->table('orders')->where('id', $order)->whereNull('customer');
        $row = $claim->first();
        abort_unless($row && strcasecmp($user->email, $row->email) === 0 && \Sellissimo\Core\OrderLinks::valid($request, $row), 404);
        PrivateResponse::forSite($row->site);
        if ($request->isMethod('get')) {
            return view('sellissimo::claim', ['url' => $request->fullUrl(), 'id' => OrderNumber::display($row)]);
        }
        abort_unless($claim->where('email', $row->email)->where('contact_revision', $row->contact_revision)->update(['customer' => (string) $user->getAuthIdentifier()]), 409);

        return to_route('sellissimo.history', $row->site === Site::current()->handle() ? [] : ['site' => $row->site]);
    }
}
