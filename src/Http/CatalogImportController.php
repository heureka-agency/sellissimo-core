<?php

namespace Sellissimo\Core\Http;

use Sellissimo\Core\StaffSites;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Sellissimo\Core\Catalog;
use Sellissimo\Core\CatalogExport;
use Sellissimo\Core\CatalogImport;
use Sellissimo\Core\CatalogJobs;
use Sellissimo\Core\Store;
use Statamic\Facades\CP\Toast;

class CatalogImportController
{
    public function index(Request $request, CatalogJobs $jobs, Store $store)
    {
        $input = $request->validate(['batch' => 'nullable|uuid', 'preview_page' => 'nullable|integer|min:1', 'site' => 'sometimes|required|string|max:100']);
        $site = $input['site'] ?? StaffSites::selected();
        StaffSites::authorize($site);
        $id = $input['batch'] ?? $request->session()->get('sellissimo.import.preview');
        $recent = $store->table('catalog_jobs')->where('staff', $request->user()->id())->whereIn('site', StaffSites::handles())->whereNull('completed_at')
            ->where('created_at', '>=', now()->subDays(7))->orderByDesc('created_at')->limit(20)->get(['id', 'site', 'collection', 'rows', 'created_at']);

        return Inertia::render('sellissimo::CatalogImport', ['sites' => StaffSites::allowed()->map->name(), 'collections' => Catalog::collections(), 'site' => $site,
            'preview' => $id ? $jobs->preview($id, $request->user(), $input['preview_page'] ?? 1) : null,
            'recent' => $recent, 'listUrl' => cp_route('sellissimo.import'),
            'previewUrl' => cp_route('sellissimo.import.preview'), 'applyUrl' => cp_route('sellissimo.import.apply'),
            'templateUrl' => cp_route('sellissimo.import.template'), 'exportUrl' => cp_route('sellissimo.import.export')]);
    }

    public function export(Request $request, CatalogExport $catalog)
    {
        $data = $request->validate(['site' => 'required|string|max:100', 'collection' => 'required|string|max:100', 'images' => 'nullable|boolean']);

        return $catalog->download($data['site'], $data['collection'], $request->user(), $request->boolean('images'));
    }

    public function template()
    {
        return response(implode(',', CatalogImport::COLUMNS)."\r\n", 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="sellissimo-products.csv"']);
    }

    public function preview(Request $request, CatalogJobs $jobs)
    {
        $data = $request->validate(['file' => 'required|file|max:102400', 'site' => 'required|string|max:100', 'collection' => 'required|string|max:100']);
        $id = $jobs->stage($data['file'], $data['site'], $data['collection'], $request->user());
        $request->session()->put('sellissimo.import.preview', $id);

        return redirect(cp_route('sellissimo.import'));
    }

    public function apply(Request $request, CatalogJobs $jobs)
    {
        $id = $request->validate(['token' => 'required|uuid'])['token'];
        try {
            $complete = $jobs->apply($id, $request->user());
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['token' => 'Another import is running. Try confirming this preview again shortly.']);
        }
        if ($complete) {
            $request->session()->forget('sellissimo.import.preview');
            Toast::success(__('Catalog import complete.'));
        } else {
            $request->session()->put('sellissimo.import.preview', $id);
        }

        return redirect(cp_route('sellissimo.import'));
    }
}
