<?php

namespace Sellissimo\Core\Http;

use Sellissimo\Core\StaffSites;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Sellissimo\Core\CartRecovery;
use Statamic\Facades\CP\Toast;
use Statamic\Facades\Site;

class CartRecoveryController
{
    public function index(Request $request, CartRecovery $recovery)
    {
        $filters = $request->validate(['site' => OrderListing::FILTERS['site'], 'view' => 'nullable|in:recovered', 'q' => 'nullable|string|max:254']);
        $resumed = ($filters['view'] ?? '') === 'recovered';
        $query = $resumed ? $recovery->recovered() : $recovery->inactive();
        $records = $query->whereIn('site', StaffSites::handles())->when($filters['site'] ?? null, fn ($q, $site) => $q->where('site', $site))
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->whereLike('recovery_email', '%'.$term.'%'))
            ->orderByDesc('active_at')->orderBy('id')->paginate(25)->withQueryString();
        $records->through(fn ($row) => ['id' => $row->id, 'email' => $row->recovery_email, 'site' => $row->site, 'active' => $row->active_at,
            'count' => collect(json_decode($row->items, true))->sum(fn ($item) => is_array($item) ? $item['qty'] : $item),
            'status' => ($row->order_id ?? null) ? 'Ordered' : ($row->recovered_at ? 'Reopened' : ($row->reminded_at ? 'Reminder sent' : 'Inactive')),
            'remindUrl' => ! $resumed && $row->recovery_email && ! $row->reminded_at ? cp_route('sellissimo.recovery.remind', $row->id) : null]);

        return Inertia::render('sellissimo::Recovery', ['results' => $records, 'filters' => $filters, 'sites' => StaffSites::allowed()->map->name(), 'listUrl' => cp_route('sellissimo.recovery')]);
    }

    public function remind(string $cart, CartRecovery $recovery)
    {
        $recovery->remind($cart);
        Toast::success(__('Cart reminder sent.'));

        return back();
    }

    public function recover(Request $request, string $cart, CartRecovery $recovery)
    {
        [$row, $key] = $recovery->claim($request, $cart);
        PrivateResponse::forSite($row->site);
        if (! $key) {
            return view('sellissimo::recover', ['items' => json_decode($row->items, true), 'titles' => json_decode($row->line_titles ?? '{}', true), 'url' => $request->fullUrl()]);
        }
        $request->session()->put('sellissimo.cart.'.$row->site, $key);
        $request->session()->forget('sellissimo.quote.'.$row->site);
        if ($request->expectsJson()) {
            return response()->json($key);
        }
        $url = \Sellissimo\Core\OperationalSettings::storefront($row->site) ? route('sellissimo.cart.page', ['site' => $row->site])
            : (\Sellissimo\Core\StoreSettings::forSection($row->site, 'emails', 'recovery_storefront_url', config('sellissimo.recovery.storefront_urls.'.$row->site)) ?: Site::get($row->site)->absoluteUrl());

        return redirect()->to($url);
    }
}
