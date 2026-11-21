<?php

namespace Sellissimo\Core\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Sellissimo\Core\Catalog;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

class PrivateProductFileController
{
    public function __invoke(Request $request)
    {
        $context = $request->validate(['collection' => 'required|string|max:100', 'site' => 'required|string|max:100', 'entry' => 'nullable|string|max:150']);
        abort_unless(in_array($context['collection'], Catalog::collections(), true), 404);
        $collection = Collection::findByHandle($context['collection']);
        $site = Site::all()->get($context['site']);
        abort_unless($site && $collection && $collection->sites()->contains($site->handle()), 404);
        if ($id = $context['entry'] ?? null) {
            $entry = Entry::find($id);
            abort_unless($entry && $entry->collectionHandle() === $collection->handle() && $entry->locale() === $site->handle(), 404);
            abort_unless($request->user()->can('edit', $entry), 403);
        } else {
            abort_unless($request->user()->can('create', [EntryContract::class, $collection, $site]), 403);
        }
        $file = $request->validate(['file' => 'required|file|max:'.(int) config('sellissimo.download_upload_kb', 51200)])['file'];
        $name = trim(preg_replace('/[^\pL\pN._() -]+/u', '-', $file->getClientOriginalName()), ' .') ?: 'download';
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $suffix = filled($extension) && strlen($extension) <= 20 ? '.'.$extension : '';
        $stem = $suffix ? substr($name, 0, -strlen($suffix)) : $name;
        $name = mb_strcut($stem, 0, 200 - strlen($suffix), 'UTF-8').$suffix;
        $path = Storage::disk(config('sellissimo.download_disk'))->putFileAs('products/'.Str::uuid(), $file, $name, 'private');
        abort_unless($path, 503);

        return response()->json(['value' => $path, 'label' => $name], 201);
    }
}
