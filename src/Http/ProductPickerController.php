<?php

namespace Sellissimo\Core\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Sellissimo\Core\Catalog;
use Sellissimo\Core\CatalogBrowse;
use Sellissimo\Core\Checkout;

class ProductPickerController
{
    public function __invoke(Request $request, Catalog $products, CatalogBrowse $catalog, ?string $order = null)
    {
        $site = $order ? app(Checkout::class)->order($order)->site : $request->validate(['site' => 'required|string|max:100'])['site'];
        $data = $request->validate(['sku' => 'sometimes|string|max:100',
            'q' => 'nullable|string|max:150', 'page' => 'sometimes|integer|min:1|max:1000000', 'per_page' => 'sometimes|integer|between:1,50']);
        if (isset($data['sku'])) {
            $sku = strtoupper($data['sku']);
            $product = $products->products($site, false, [$sku])[$sku] ?? null;
            abort_unless($product, 404);
            return response()->json(Arr::only($product, ['sku', 'title', 'digital', 'option_fields']));
        }
        [$page] = $catalog->page($site, ['per_page' => 12, ...$data], picker: true);
        return response()->json(['products' => $page->items(), 'pagination' => [
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
        ]]);
    }
}
