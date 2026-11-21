<?php

namespace Sellissimo\Core\Http;

use Illuminate\Http\Request;
use Sellissimo\Core\StockSubscriptions;
use Sellissimo\Core\Store;
use Sellissimo\Core\StoreFeatures;
use Sellissimo\Core\Wishlists;
use Statamic\Facades\Site;

class ShopFeaturesController
{
    private function site(Request $request, string $feature): string
    {
        $site = $request->validate(['site' => 'sometimes|alpha_dash|max:100'])['site'] ?? Site::current()->handle();
        abort_unless(Site::all()->has($site) && StoreFeatures::enabled($site, $feature), 404);
        PrivateResponse::forSite($site);
        return $site;
    }

    public function wishes(Request $request, Wishlists $lists)
    {
        $site = $this->site($request, 'wishlists');
        if ($request->isMethod('post')) {
            $input = $request->validate(['sku' => 'required|string|max:100', 'action' => 'required|in:add,remove']);
            $lists->change($request, $site, strtoupper(trim($input['sku'])), $input['action'] === 'add');
            if (!$request->expectsJson()) {
                return to_route('sellissimo.wishes', compact('site'));
            }
        }
        $items = $lists->items($request, $site);
        return $request->expectsJson() ? response()->json(compact('items')) : view('sellissimo::wishlist', compact('items', 'site'));
    }

    public function subscribe(Request $request, StockSubscriptions $alerts)
    {
        $site = $this->site($request, 'back_in_stock');
        $input = $request->validate(['sku' => 'required|string|max:100', 'email' => 'required|email|max:254']);
        $alerts->subscribe($site, strtoupper(trim($input['sku'])), $input['email']);
        $message = __('sellissimo::features.check_email');
        return $request->expectsJson() ? response()->json(compact('message'), 202)
            : view('sellissimo::stock-subscription', ['message' => $message, 'action' => null, 'site' => $site]);
    }

    public function consent(Request $request, string $subscription, Store $store)
    {
        $query = $store->table('stock_subscriptions')->where('id', $subscription);
        $row = $query->firstOrFail();
        $site = $row->site;
        PrivateResponse::forSite($site);
        $remove = $request->routeIs('sellissimo.stock.unsubscribe');
        abort_unless($remove || StoreFeatures::enabled($site, 'back_in_stock'), 404);
        $action = $remove ? 'unsubscribe' : 'confirm';
        $message = __('sellissimo::features.'.$action.'_hint', ['sku' => $row->sku]);
        if ($request->isMethod('post')) {
            if ($remove) {
                $query->delete();
            } else {
                $query->whereNull('confirmed_at')->update(['confirmed_at' => now()]);
            }
            $message = __('sellissimo::features.'.($remove ? 'unsubscribed' : 'confirmed'));
            $action = null;
        }
        return view('sellissimo::stock-subscription', compact('action', 'message', 'site'));
    }
}
