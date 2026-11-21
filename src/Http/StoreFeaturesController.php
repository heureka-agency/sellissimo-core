<?php

namespace Sellissimo\Core\Http;

use Sellissimo\Core\StaffSites;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Sellissimo\Core\Store;
use Sellissimo\Core\StoreFeatures;
use Statamic\Facades\CP\Toast;

class StoreFeaturesController
{
    public function settings(Request $request)
    {
        $sites = StaffSites::allowed()->map->name();
        $site = $request->validate(['site' => 'sometimes|string|max:100'])['site'] ?? StaffSites::selected();
        $scope = in_array($request->query('from'), ['checkout', 'emails'], true) ? $request->query('from') : null;
        $from = $scope ?? 'checkout';
        StaffSites::authorize($site);
        if ($request->isMethod('post')) {
            $data = $request->validate([
                'revision' => 'required|integer|min:0', 'features' => 'array', 'features.*' => [Rule::in(StoreFeatures::FLAGS)],
                'recipients' => 'array', 'recipients.*' => 'array|max:30', 'recipients.*.*' => 'email|max:254',
            ]);
            $current = StoreFeatures::get($site);
            $recipients = [];
            foreach (StoreFeatures::ALERTS as $topic) {
                $recipients[$topic] = array_values(array_unique(array_map(fn ($email) => mb_strtolower(trim($email)), $data['recipients'][str_replace('.', '_', $topic)] ?? [])));
            }
            // Old callers may submit both groups. The two focused editors update only their own group.
            $combined = $request->has(['features', 'recipients']);
            $features = !$combined && $scope === 'emails' ? $current['features'] : ($data['features'] ?? []);
            if (!$combined && $scope === 'checkout') {
                $recipients = $current['recipients'];
            }
            StoreFeatures::save($site, $features, $recipients, $data['revision']);
            Toast::success(__('Store settings saved.'));
            return redirect(cp_route('sellissimo.features', compact('site', 'from')));
        }
        return Inertia::render('sellissimo::StoreFeatures', [
            'site' => $site, 'multisite' => \Statamic\Facades\Site::all()->count() > 1, 'sites' => $sites, 'settings' => StoreFeatures::get($site),
            'settingsUrl' => cp_route('sellissimo.settings.section', ['section' => $from, 'site' => $site]),
            'settingsTitle' => $from === 'emails' ? __('Emails and notifications') : __('Checkout and customers'),
            'url' => cp_route('sellissimo.features', compact('from')), 'section' => $from,
        ]);
    }

    public function demand(Request $request, Store $store)
    {
        $sites = StaffSites::allowed()->map->name();
        $site = $request->validate(['site' => 'sometimes|string|max:100'])['site'] ?? StaffSites::selected();
        StaffSites::authorize($site);
        $rows = $store->table('stock_subscriptions')->where('site', $site)->whereNotNull('confirmed_at')->whereNull('notified_at')
            ->groupBy('sku')->select('sku')->selectRaw('count(*) as waiting')->orderByDesc('waiting')->orderBy('sku')->paginate(50)->appends(compact('site'));
        return Inertia::render('sellissimo::StockDemand', ['site' => $site, 'sites' => $sites, 'results' => $rows, 'url' => cp_route('sellissimo.stock-demand')]);
    }
}
