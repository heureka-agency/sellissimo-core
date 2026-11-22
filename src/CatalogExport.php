<?php

namespace Sellissimo\Core;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use ZipArchive;

class CatalogExport
{
    public static function row($entry, ?array $variant, string $currency): array
    {
        $value = fn ($key) => $variant === null ? $entry->value('sellissimo_'.$key) : ($variant[$key] ?? null);
        $row = ['sku' => $value('sku'), 'product_handle' => $entry->slug(), 'variant' => $variant['label'] ?? '',
            'title' => $entry->value('title'), 'published' => (int) $entry->published()];
        foreach (['price' => 'prices', 'sale_price' => 'sale_prices', 'cost' => 'costs'] as $column => $key) {
            $amount = $value($key)[$currency] ?? null;
            $row[$column] = $amount === null ? '' : Money::decimal((int) $amount, $currency);
        }
        foreach (['barcode', 'mpn', 'weight_kg', 'length_cm', 'width_cm', 'height_cm'] as $key) {
            $row[$key] = $value($key);
        }
        foreach (['categories', 'tags', 'gallery'] as $key) {
            $items = (array) $entry->value('sellissimo_'.$key);
            if ($key !== 'gallery') {
                $items = array_map(fn ($term) => Str::after($term, '::'), $items);
            }
            $row[$key] = $items ? json_encode(array_values($items), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : '';
        }
        $row['attributes'] = empty($variant['attributes']) ? '' : json_encode((object) $variant['attributes'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $row['variant_image'] = (array) ($variant['image'] ?? []);
        $row['variant_image'] = reset($row['variant_image']) ?: '';
        $row['stock'] = '';
        $description = $entry->value('sellissimo_description');
        $row['description'] = is_string($description) ? $description : '';
        $row['description_bard'] = is_array($description)
            ? json_encode($description, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : '';
        foreach (CatalogImport::COLUMNS as $column) {
            if (! array_key_exists($column, $row)) {
                $field = $column === 'country_of_origin' ? 'origin' : $column;
                $row[$column] = $entry->value('sellissimo_'.$field);
            }
        }

        return array_map(function ($column) use ($row) {
            $value = $row[$column];
            if (is_bool($value)) {
                return (string) (int) $value;
            }

            return is_scalar($value) ? (string) $value : '';
        }, array_combine(CatalogImport::COLUMNS, CatalogImport::COLUMNS));
    }

    private function stock($entries): array
    {
        $skus = $entries->flatMap(fn ($entry) => $entry->value('sellissimo_variants')
            ? array_column($entry->value('sellissimo_variants'), 'sku') : [$entry->value('sellissimo_sku')])->filter()->unique();
        $balances = [];
        $store = app(Store::class);
        foreach ($skus->chunk(400) as $chunk) {
            foreach ($store->table('stock')->whereIn('sku', $chunk)->get(['sku', 'available']) as $row) {
                $balances[$row->sku] = ['main' => (int) $row->available];
            }
            foreach ($store->table('balances')->whereIn('sku', $chunk)->orderBy('location')->get() as $row) {
                if (isset($balances[$row->sku])) {
                    $balances[$row->sku][$row->location] = (int) $row->quantity;
                    $balances[$row->sku]['main'] -= (int) $row->quantity;
                }
            }
        }

        return $balances;
    }

    private function csv($stream, array $values): string
    {
        rewind($stream);
        ftruncate($stream, 0);
        fputcsv($stream, $values, ',', '"', '', "\r\n");
        rewind($stream);

        return stream_get_contents($stream);
    }

    public function download(string $site, string $handle, $user, bool $withImages = false)
    {
        $collection = Collection::findByHandle($handle);
        abort_unless(in_array($handle, Catalog::collections(), true) && $collection?->sites()->contains($site), 422);
        $currency = app(Catalog::class)->currency($site);
        $directory = sys_get_temp_dir().'/sellissimo-export-'.Str::uuid();
        File::makeDirectory($directory, 0700);
        $stream = fopen('php://temp', 'w+');
        $output = null;
        $files = [];
        $header = $this->csv($stream, CatalogImport::COLUMNS);
        $name = 'sellissimo-'.$site.'-'.$handle;
        try {
            $rows = 0;
            $size = 0;
            $openPart = function () use (&$files, &$output, &$rows, &$size, $header, $directory, $name) {
                if (is_resource($output)) {
                    fclose($output);
                }
                $part = $directory.'/'.$name.'-'.(count($files) + 1).'.csv';
                $files[] = $part;
                $output = fopen($part, 'wb');
                fwrite($output, $header);
                $size = strlen($header);
                $rows = 0;
            };
            $openPart();
            $includeStock = $user->can('manage sellissimo inventory');
            $images = [];
            $container = AssetContainer::find('sellissimo_products');
            if ($withImages) {
                abort_unless($container && $user->can('view', $container), 403);
            }
            $entries = Entry::query()->where('collection', $handle)->where('site', $site)->orderBy('slug')->orderBy('id')->lazy(200);
            foreach ($entries->chunk(100) as $entriesChunk) {
                $visibleEntries = $entriesChunk->filter(fn ($entry) => $user->can('edit', $entry))->collect();
                $stock = $includeStock ? $this->stock($visibleEntries) : [];
                foreach ($visibleEntries as $entry) {
                    foreach ($entry->value('sellissimo_variants') ?: [null] as $variant) {
                        $row = self::row($entry, $variant, $currency);
                        if ($row['sku'] === '') {
                            continue;
                        }
                        if ($includeStock && $entry->value('sellissimo_track_stock') !== false && ! $entry->value('sellissimo_service')
                            && ! filled($variant ? ($variant['download'] ?? null) : $entry->value('sellissimo_download'))) {
                            if (isset($stock[$row['sku']])) {
                                $row['stock'] = json_encode((object) $stock[$row['sku']], JSON_THROW_ON_ERROR);
                            }
                        }
                        if ($withImages) {
                            $description = $entry->value('sellissimo_description');
                            $embedded = is_array($description) ? ProductDescription::images($description) : [];
                            foreach ([...json_decode($row['gallery'] ?: '[]', true), $row['variant_image'], ...$embedded] as $path) {
                                if ($path !== '') {
                                    $images[CatalogImportMedia::path($path)] = true;
                                }
                            }
                        }
                        // Quoted CSV can still be interpreted as a spreadsheet formula. Escape literal values reversibly.
                        $line = $this->csv($stream, array_map([CatalogImport::class, 'spreadsheetCell'], $row));
                        abort_if(strlen($header) + strlen($line) > CatalogImport::MAX_BYTES, 422, 'A product row exceeds the CSV import limit.');
                        if ($rows >= CatalogImport::MAX_ROWS || $size + strlen($line) > CatalogImport::MAX_BYTES) {
                            $openPart();
                        }
                        fwrite($output, $line);
                        $rows++;
                        $size += strlen($line);
                    }
                }
            }
            fclose($output);
            $output = null;
            $file = $files[0];
            $extension = 'csv';
            if (count($files) > 1 || $withImages) {
                $extension = 'zip';
                $zip = new ZipArchive;
                $file = $directory.'/'.$name.'.zip';
                throw_unless($zip->open($file, ZipArchive::CREATE) === true, 'Could not create catalog archive.');
                foreach ($files as $part) {
                    $zip->addFile($part, basename($part));
                }
                $imageDescriptions = [];
                foreach (array_keys($images) as $path) {
                    $asset = $container->asset($path);
                    abort_unless($asset && $asset->isImage() && $user->can('view', $asset), 422, "Product image unavailable: $path.");
                    $zip->addFromString('images/'.$path, $container->disk()->filesystem()->get($path));
                    if (is_string($alt = $asset->get('alt')) && $alt !== '') {
                        $imageDescriptions[$path] = $alt;
                    }
                }
                if ($imageDescriptions) {
                    $zip->addFromString('image-alt.json', json_encode((object) $imageDescriptions, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                }
                throw_unless($zip->close(), 'Could not finish catalog archive.');
            }
            // The response owns a temporary stream independent of the staging directory.
            $download = tmpfile();
            $source = fopen($file, 'rb');
            try {
                throw_if(stream_copy_to_stream($source, $download) === false, 'Could not prepare catalog download.');
                rewind($download);
            } finally {
                fclose($source);
            }

            return response()->streamDownload(static function () use ($download) {
                try {
                    fpassthru($download);
                } finally {
                    fclose($download);
                }
            }, "$name.$extension", ['Content-Type' => $extension === 'zip' ? 'application/zip' : 'text/csv; charset=UTF-8']);
        } finally {
            if (is_resource($output)) {
                fclose($output);
            }
            fclose($stream);
            File::deleteDirectory($directory);
        }
    }
}
