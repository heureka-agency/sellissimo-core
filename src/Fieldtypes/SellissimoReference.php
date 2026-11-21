<?php

namespace Sellissimo\Core\Fieldtypes;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use League\Flysystem\FilesystemException;
use Sellissimo\Core\Downloads;
use Sellissimo\Core\Http\StoreCountries;
use Sellissimo\Core\Store;
use Statamic\Facades\Blink;
use Statamic\Fields\Fieldtype;

class SellissimoReference extends Fieldtype
{
    protected static $title = 'Sellissimo store selection';

    protected function configFieldItems(): array
    {
        return [
            'source' => ['display' => 'Choose from', 'type' => 'select', 'default' => 'tax_class',
                'options' => ['tax_class' => 'Product tax classes', 'download' => 'Private download files', 'country' => 'Countries']],
            'placeholder' => ['display' => 'Placeholder', 'type' => 'text'],
        ];
    }

    public function process($data)
    {
        if ($this->config('source') !== 'download') {
            return $data;
        }
        $paths = Downloads::paths($data);
        if (filled($data) && count($paths) !== count((array) $data)) {
            throw ValidationException::withMessages([$this->field()->handle() => 'Choose files from the private product library.']);
        }

        return count($paths) > 1 ? $paths : ($paths[0] ?? null);
    }

    public function preload()
    {
        $source = $this->config('source');
        if ($source === 'country') {
            return ['options' => collect(StoreCountries::labels())->map(fn ($label, $value) => compact('label', 'value'))->values()->all()];
        }
        if ($source === 'download') {
            // Only entry editors using the control panel should see the private file inventory.
            if (! request()->routeIs('statamic.cp.*') || ! request()->user()?->can('access cp')) {
                return ['options' => []];
            }

            $entry = $this->field()->parent();
            $collection = $entry instanceof \Statamic\Contracts\Entries\Entry ? $entry->collection() : request()->route('collection');
            $site = $entry instanceof \Statamic\Contracts\Entries\Entry ? $entry->site() : request()->route('site');
            $upload = [];
            $canBrowse = request()->user()->can('configure collections');
            if ($collection instanceof \Statamic\Contracts\Entries\Collection && $site instanceof \Statamic\Sites\Site
                && in_array($collection->handle(), \Sellissimo\Core\Catalog::collections(), true)) {
                $id = $entry instanceof \Statamic\Contracts\Entries\Entry ? $entry->id() : null;
                $canUpload = $id ? request()->user()->can('edit', $entry)
                    : request()->user()->can('create', [\Statamic\Contracts\Entries\Entry::class, $collection, $site]);
                $canBrowse = $canUpload;
                if ($canUpload) {
                    $upload = ['uploadUrl' => cp_route('sellissimo.private-downloads', ['collection' => $collection->handle(), 'site' => $site->handle(), 'entry' => $id]),
                        'csrfToken' => csrf_token()];
                }
            }

            if (!$canBrowse) {
                return ['options' => []];
            }

            return $upload + Blink::once('sellissimo.private-downloads', function () {
                try {
                    $files = Storage::disk(config('sellissimo.download_disk'))->allFiles();
                    natcasesort($files);

                    return ['options' => collect($files)->filter(fn ($path) => ! str_starts_with($path, 'order-artwork/') && ! str_starts_with($path, '.') && ! str_contains($path, '/.'))
                        ->map(fn ($path) => ['value' => $path, 'label' => $path])->values()->all()];
                } catch (FilesystemException|\InvalidArgumentException $exception) {
                    report($exception);

                    return ['options' => [], 'message' => 'Private storage could not be read. Check the download disk settings.'];
                }
            });
        }

        $classes = collect(config('sellissimo.taxes', []))->flatMap(fn ($rates) => array_keys($rates))
            ->push('standard')->mapWithKeys(fn ($key) => [$key => Str::headline($key)])
            ->replace(app(Store::class)->table('tax_classes')->pluck('name', 'handle'))->sort(SORT_NATURAL | SORT_FLAG_CASE);

        return ['options' => $classes->map(fn ($name, $key) => ['value' => (string) $key, 'label' => $name])->values()->all()];
    }
}
