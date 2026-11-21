<?php

namespace Sellissimo\Core\Http;

use Sellissimo\Core\StaffSites;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Sellissimo\Core\Catalog;
use Sellissimo\Core\GiftVouchers;
use Sellissimo\Core\Money;
use Sellissimo\Core\Store;
use Sellissimo\Core\StoreFeatures;
use Statamic\Facades\CP\Toast;
use Statamic\Facades\Site;

class GiftVoucherController
{
    private function site(Request $request): string
    {
        $site = $request->validate(['site' => 'nullable|string|max:100'])['site'] ?? ($request->routeIs('statamic.cp.*') ? StaffSites::selected() : Site::default()->handle());
        abort_unless(Site::all()->has($site), 404);
        return $site;
    }

    public function index(Request $request, Store $store, Catalog $catalog)
    {
        $site = $this->site($request);
        $enabled = StoreFeatures::enabled($site, 'vouchers');
        $currency = $catalog->currency($site);
        $records = $store->table('vouchers')->where('site', $site)->orderByDesc('created_at')->orderBy('id')
            ->paginate(25, ['id', 'ending', 'recipient', 'face', 'balance', 'currency', 'sent_at'])->withQueryString();
        $records->through(fn ($row) => ['id' => $row->id, 'ending' => $row->ending, 'recipient' => $row->recipient,
            'face' => Money::display($row->face, $row->currency), 'balance' => Money::display($row->balance, $row->currency), 'sent' => (bool) $row->sent_at]);
        $selected = $request->validate(['voucher' => 'nullable|uuid'])['voucher'] ?? null;
        $activity = null;
        if ($selected) {
            $voucher = $store->table('vouchers')->where('site', $site)->where('id', $selected)->firstOrFail();
            $activity = $store->table('voucher_movements')->where('voucher_id', $selected)->orderByDesc('id')
                ->paginate(20, ['id', 'kind', 'amount', 'created_at', 'order_id', 'operation'], 'activity_page')->withQueryString();
            $activity->through(fn ($row) => ['id' => $row->id, 'kind' => $row->kind, 'when' => $row->created_at,
                'reference' => '#'.$row->id, 'actor' => $row->kind === 'issue' ? $voucher->actor : null,
                'amount' => Money::display($row->amount, $voucher->currency), 'orderUrl' => $row->order_id && $request->user()->can('view sellissimo orders')
                    ? cp_route('sellissimo.order', $row->order_id) : null]);
        }
        return Inertia::render('sellissimo::Vouchers', ['selected' => $selected, 'activity' => $activity, 'records' => $records, 'site' => $site, 'currency' => $currency, 'enabled' => $enabled,
            'sites' => StaffSites::allowed()->map(fn ($candidate) => $candidate->name())->all(), 'requestId' => (string) Str::uuid(),
            'listUrl' => cp_route('sellissimo.vouchers'), 'issueUrl' => cp_route('sellissimo.vouchers.issue')]);
    }

    public function issue(Request $request, Catalog $catalog, GiftVouchers $vouchers)
    {
        $site = $this->site($request);
        abort_unless(StoreFeatures::enabled($site, 'vouchers'), 422, __('Enable gift vouchers for this site first.'));
        $data = $request->validate(['request_id' => 'required|uuid', 'recipient' => 'required|email|max:254', 'amount' => 'required|string|max:24']);
        $currency = $catalog->currency($site);
        $minor = Money::parseDecimal($data['amount'], Money::fractionDigits($currency), 'amount');
        $voucher = $vouchers->issue('staff:'.$data['request_id'], $site, $currency, $minor, trim($data['recipient']), actor: $request->user()->email());
        try {
            $vouchers->send($voucher->id);
        } catch (\Throwable $error) {
            report($error);
        }
        Toast::success(__('Gift voucher issued.'));
        return to_route('statamic.cp.sellissimo.vouchers', ['site' => $site]);
    }

    public function balance(Request $request, Catalog $catalog, GiftVouchers $vouchers)
    {
        $site = $this->site($request);
        PrivateResponse::forSite($site);
        abort_unless(StoreFeatures::enabled($site, 'vouchers'), 404);
        $balance = null;
        if ($request->isMethod('post')) {
            $code = $request->validate(['voucher' => 'required|string|max:80'])['voucher'];
            $row = $vouchers->byCode($code, $site, null);
            $balance = ['ending' => $row->ending, 'amount' => (int) $row->balance, 'currency' => $row->currency];
        }
        return $request->expectsJson() ? response()->json(['balance' => $balance]) : view('sellissimo::voucher-balance', compact('balance', 'site'));
    }
}
