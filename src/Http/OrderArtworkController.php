<?php

namespace Sellissimo\Core\Http;

use Sellissimo\Core\StaffSites;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Sellissimo\Core\Carts;
use Sellissimo\Core\CommerceFields;
use Sellissimo\Core\Store;

class OrderArtworkController
{
    public function upload(Request $request, string $scope, CommerceFields $fields, Carts $carts)
    {
        $staff = $request->routeIs('statamic.cp.sellissimo.drafts.upload');
        if ($staff) {
            // A new draft's creation ID has no stored cart yet; its site is checked on draft save.
            $site = app(Store::class)->table('drafts')->join('sellissimo_carts as c', 'c.id', '=', 'sellissimo_drafts.cart_id')
                ->where('sellissimo_drafts.id', $scope)->value('c.site');
            if ($site !== null) {
                StaffSites::authorize($site);
            }
        } else {
            $cart = $carts->get($scope, $request->bearerToken(), $request->user()?->getAuthIdentifier());
            $scope = CommerceFields::scope($cart);
        }
        $data = $request->validate(['type' => 'required|in:order,line', 'handle' => 'required|alpha_dash|max:100', 'file' => 'required|file|max:10240']);
        $file = $fields->upload($scope, $data['type'], $data['handle'], $data['file'], $staff);
        return response()->json(['id' => $file->id, 'name' => $file->name], 201);
    }

    public function download(string $order, string $file, Store $store)
    {
        $row = $store->table('orders')->where('id', $order)->firstOrFail();
        $receipt = json_decode($row->receipt, true);
        $groups = [array_filter(json_decode($row->operational_fields ?? '{}', true)), $receipt['order_fields'] ?? [], ...array_column($receipt['lines'], 'line_fields')];
        abort_unless(collect($groups)->contains(fn ($fields) => in_array($file, array_column($fields, 'file_id'), true)), 404);
        $upload = $store->table('field_uploads')->where('id', $file)->firstOrFail();
        return Storage::disk($upload->disk)->download($upload->path, $upload->name, ['X-Content-Type-Options' => 'nosniff']);
    }
}
