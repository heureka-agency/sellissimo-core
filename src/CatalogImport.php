<?php

namespace Sellissimo\Core;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Sellissimo\Core\Http\StoreCountries;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

class CatalogImport
{
    public const MAX_BYTES = 2097152;

    public const MAX_ROWS = 2000;

    public static function spreadsheetCell(string $value): string
    {
        return preg_match("/^(?:[\\s]*[=+@-]|['\\t\\r\\n])/", $value) || $value === '__CLEAR__' ? "'".$value : $value;
    }

    public const COLUMNS = ['sku', 'product_handle', 'variant', 'title', 'price', 'sale_price', 'cost', 'barcode', 'mpn', 'summary', 'description', 'brand', 'product_type', 'published', 'service', 'weight_kg', 'length_cm', 'width_cm', 'height_cm', 'sale_starts', 'sale_ends', 'unit', 'unit_quantity', 'country_of_origin', 'hs_code', 'featured', 'seo_title', 'seo_description', 'categories', 'tags', 'gallery', 'variant_image', 'attributes', 'stock', 'description_bard'];

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }

    private function rows(string $csv): \Generator
    {
        if (! mb_check_encoding($csv, 'UTF-8')) {
            $this->invalid('Save the CSV as UTF-8.');
        }
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, preg_replace('/^\xEF\xBB\xBF/', '', $csv));
        rewind($stream);
        try {
            $header = array_map(fn ($v) => strtolower(trim((string) $v)), fgetcsv($stream, 0, ',', '"', '') ?: []);
            if (! in_array('sku', $header, true) || array_diff($header, self::COLUMNS) || count(array_unique($header)) !== count($header)) {
                $this->invalid('Use the template headers, including sku, with each column appearing once.');
            }
            $count = 0;
            for ($record = 2; ($values = fgetcsv($stream, 0, ',', '"', '')) !== false; $record++) {
                if ($values === [null]) {
                    continue;
                }
                if (++$count > 20000) {
                    $this->invalid('Import up to 20,000 rows per batch.');
                }
                if (count($header) !== count($values)) {
                    $this->invalid("Row $record has a different number of columns from the header.");
                }
                yield array_map('trim', array_combine($header, $values));
            }
            if (! $count) {
                $this->invalid('Add at least one product row.');
            }

        } finally {
            fclose($stream);
        }
    }

    private function batchRows(array $parts): \Generator
    {
        $rowNumber = 1;
        foreach ($parts as $csv) {
            foreach ($this->rows($csv) as $row) {
                if (++$rowNumber > 20001) {
                    $this->invalid('Import up to 20,000 rows per batch.');
                }
                yield $rowNumber => $row;
            }
        }
    }

    public function plan(string|array $csv, string $site, string $handle, $user, array $images = []): array
    {
        $currency = app(Catalog::class)->currency($site);
        $collection = Collection::findByHandle($handle);
        abort_unless(in_array($handle, Catalog::collections(), true) && $collection?->sites()->contains($site), 422);
        $entries = Entry::query()->whereIn('collection', Catalog::collections())->where('site', $site)->get();
        $owners = [];
        $slugs = [];
        foreach ($entries as $entry) {
            if ($entry->collectionHandle() === $handle) {
                $slugs[$entry->slug()][] = $entry;
            }
            $variants = $entry->value('sellissimo_variants') ?: [];
            foreach ($variants ?: [['sku' => $entry->value('sellissimo_sku')]] as $i => $variant) {
                if (filled($variant['sku'] ?? null)) {
                    $owners[strtoupper(trim($variant['sku']))][] = [$entry, $variants ? $i : null];
                }
            }
        }
        $groups = [];
        $rows = [];
        $seen = [];
        $issues = 0;
        $shared = [];
        $media = app(CatalogImportMedia::class);
        $inventory = app(Inventory::class);
        $locations = null;
        foreach ($this->batchRows((array) $csv) as $number => $row) {
            $sku = strtoupper($row['sku']);
            $variant = $row['variant'] ?? '';
            $slug = $row['product_handle'] ?? '';
            try {
                if (! preg_match('/^[A-Z0-9][A-Z0-9_.-]{0,99}$/D', $sku)) {
                    $this->invalid('Enter a valid SKU.');
                }
                if (isset($seen[$sku]) || count($owners[$sku] ?? []) > 1) {
                    $this->invalid('The SKU occurs more than once in this file or catalog.');
                }
                $seen[$sku] = true;
                [$entry,$index] = $owners[$sku][0] ?? [null, null];
                $existingSku = $entry !== null;
                if ($entry && ($entry->collectionHandle() !== $handle || ($slug !== '' && $slug !== $entry->slug()))) {
                    $this->invalid('This SKU belongs to another product.');
                }
                $slug = $slug ?: ($entry?->slug() ?? Str::slug($sku));
                if (! preg_match('/^[a-z0-9][a-z0-9_-]{0,149}$/D', $slug)) {
                    $this->invalid('Use a lowercase product handle.');
                }
                if (count($slugs[$slug] ?? []) > 1) {
                    $this->invalid('More than one entry has this product handle.');
                }
                $entry ??= $slugs[$slug][0] ?? null;
                if ($entry && ! $existingSku && ! $entry->value('sellissimo_variants')) {
                    $this->invalid('This handle already belongs to an existing entry. Choose a new handle for this product.');
                }
                if ($entry && ! $user->can('edit', $entry)) {
                    $this->invalid('You do not have access to edit this product.');
                }
                if (! $entry && ! $user->can('store', [\Statamic\Contracts\Entries\Entry::class, $collection, Site::get($site)])) {
                    $this->invalid('You do not have access to create entries in this collection.');
                }
                $stockCell = $row['stock'] ?? '';
                unset($row['stock']);
                if ($existingSku && isset($row['product_handle'], $row['seo_description'])) {
                    $currentVariant = $index === null ? null : $entry->value('sellissimo_variants')[$index];
                    $current = CatalogExport::row($entry, $currentVariant, $currency);
                    $changes = array_filter($row, fn ($v, $k) => ! in_array($k, ['sku', 'product_handle'], true) && $v !== '' && $v !== self::spreadsheetCell($current[$k]), ARRAY_FILTER_USE_BOTH);
                    if ($images) {
                        // Detect conflicting archive images even when the entry already references their paths.
                        foreach (['gallery', 'variant_image', 'description_bard'] as $column) {
                            if (($row[$column] ?? '') !== '') {
                                $changes[$column] = $row[$column];
                            }
                        }
                    }
                    if (! $changes && $stockCell === '') {
                        $rows[] = ['number' => $number, 'sku' => $sku, 'action' => 'Unchanged'];

                        continue;
                    }
                    // Apply only edited cells so inherited values stay linked to their origin.
                    $row = ['sku' => $sku, 'product_handle' => $slug, ...$changes];
                }
                foreach ($row as $column => $value) {
                    if (str_starts_with($value, "'") && self::spreadsheetCell(substr($value, 1)) === $value && $value !== "'__CLEAR__") {
                        $row[$column] = substr($value, 1);
                    }
                }
                $variant = $row['variant'] ?? '';
                $before = $entry?->data()->all() ?? [];
                $group = $groups[$slug] ?? ['id' => $entry?->id(), 'before' => $before, 'data' => $before, 'published' => $entry?->published() ?? false,
                    'original_published' => $entry?->published() ?? false, 'slug' => $slug];
                $data = $group['data'];
                $variants = $data['sellissimo_variants'] ?? $entry?->value('sellissimo_variants') ?? [];
                $simple = $data['sellissimo_sku'] ?? $entry?->value('sellissimo_sku');
                if ($existingSku && $index === null && $variant !== '') {
                    $this->invalid('A simple product cannot be changed into a variant by this import.');
                }
                if (! $existingSku && ($entry || isset($groups[$slug])) && (! $variant || $simple && ! $variants)) {
                    $this->invalid('This handle already belongs to a product. Give each new simple product a unique handle.');
                }
                $isVariant = $index !== null || $variant !== '';
                if ($isVariant && ! $existingSku && blank($row['product_handle'] ?? null)) {
                    $this->invalid('New variants require a shared product_handle.');
                }
                $hasMarkdown = ($row['description'] ?? '') !== '';
                $hasBard = ($row['description_bard'] ?? '') !== '';
                if (($hasMarkdown && $hasBard) || ($hasMarkdown && isset($shared[$slug]['description_bard'])) ||
                    ($hasBard && isset($shared[$slug]['description']))) {
                    $this->invalid('Choose either description (Markdown) or description_bard (Bard JSON) per product.');
                }
                $previousData = $data;
                $wasPublished = $group['published'];
                foreach (['title' => 'title', 'summary' => 'sellissimo_summary', 'description' => 'sellissimo_description', 'brand' => 'sellissimo_brand', 'product_type' => 'sellissimo_product_type', 'published' => 'published', 'service' => 'sellissimo_service', 'featured' => 'sellissimo_featured',
                    'sale_starts' => 'sellissimo_sale_starts', 'sale_ends' => 'sellissimo_sale_ends', 'unit' => 'sellissimo_unit', 'unit_quantity' => 'sellissimo_unit_quantity',
                    'country_of_origin' => 'sellissimo_origin', 'hs_code' => 'sellissimo_hs_code', 'seo_title' => 'sellissimo_seo_title', 'seo_description' => 'sellissimo_seo_description'] as $column => $field) {
                    if (($value = $row[$column] ?? '') === '') {
                        continue;
                    }
                    if (isset($shared[$slug][$column]) && $shared[$slug][$column] !== $value) {
                        $this->invalid("Rows for this product disagree on $column.");
                    }
                    $shared[$slug][$column] = $value;
                    if ($column === 'published') {
                        if (! in_array($value, ['0', '1'], true)) {
                            $this->invalid('Published accepts 0 or 1.');
                        }
                        $group['published'] = $value === '1';
                    } elseif ($value === '__CLEAR__') {
                        if ($column === 'title') {
                            $this->invalid('A product title cannot be cleared.');
                        }
                        $data[$field] = null;
                    } else {
                        $data[$field] = $this->fieldValue($column, $value);
                    }

                }
                if (blank($data['title'] ?? $entry?->value('title'))) {
                    $this->invalid('New products need a title.');
                }
                if (mb_strlen((string) ($data['sellissimo_summary'] ?? '')) > 320) {
                    $this->invalid('The short description exceeds 320 characters.');
                }
                $target = $isVariant ? ($variants[$index] ?? []) : $data;
                $prefix = $isVariant ? '' : 'sellissimo_';
                $target[$prefix.'sku'] = $sku;
                foreach (['weight_kg', 'length_cm', 'width_cm', 'height_cm'] as $field) {
                    if (($value = $row[$field] ?? '') === '') {
                        continue;
                    }
                    $target[$prefix.$field] = $value === '__CLEAR__' ? null : $this->fieldValue($field, $value);
                }
                if ($isVariant && $variant !== '') {
                    $target['label'] = $variant === "'__CLEAR__" ? '__CLEAR__' : $variant;
                }
                foreach (['price' => 'prices', 'sale_price' => 'sale_prices', 'cost' => 'costs'] as $column => $field) {
                    if (($value = $row[$column] ?? '') === '') {
                        continue;
                    }
                    $key = $prefix.$field;
                    $prices = $target[$key] ?? ($isVariant ? [] : $entry?->value($key) ?? []);
                    if ($value === '__CLEAR__') {
                        unset($prices[$currency]);
                    } else {
                        $amount = Money::parseDecimal($value, Money::fractionDigits($currency), $column);
                        if ($amount > 99999999) {
                            $this->invalid('Price exceeds the supported maximum.');
                        }
                        $prices[$currency] = $amount;
                    }
                    $target[$key] = $prices;
                }
                $regular = $target[$prefix.'prices'][$currency] ?? null;
                $sale = $target[$prefix.'sale_prices'][$currency] ?? null;
                if ($sale !== null && ($regular === null || $sale >= $regular)) {
                    $this->invalid('Sale price must be below the regular price.');
                }
                if (! $existingSku && $regular === null) {
                    $this->invalid('New SKUs need a price for this site.');
                }
                foreach (['barcode', 'mpn'] as $field) {
                    if (($value = $row[$field] ?? '') !== '') {
                        $target[$prefix.$field] = $value === '__CLEAR__' ? null : ($value === "'__CLEAR__" ? '__CLEAR__' : $value);
                    }
                }
                $resources = $group['resources'] ?? [];
                $newResources = [];
                foreach (['categories', 'tags', 'gallery', 'attributes', 'variant_image', 'description_bard'] as $column) {
                    if (($cell = $row[$column] ?? '') === '') {
                        continue;
                    }
                    $isProductField = in_array($column, ['categories', 'tags', 'gallery', 'description_bard'], true);
                    if (! $isProductField && ! $isVariant) {
                        $this->invalid("$column requires a variant row.");
                    }
                    $value = $this->structuredValue($column, $cell);
                    if ($isProductField) {
                        if (isset($shared[$slug][$column]) && $shared[$slug][$column] !== $cell) {
                            $this->invalid("Rows for this product disagree on $column.");
                        }
                        $shared[$slug][$column] = $cell;
                    }
                    if (in_array($column, ['categories', 'tags'], true)) {
                        foreach ($value ?? [] as $term) {
                            $taxonomy = 'sellissimo_'.$column;
                            $new = $media->term($taxonomy, $term, $site, $user);
                            $resourceKey = 'term:'.$taxonomy.':'.$term;
                            $resources[$resourceKey] = ['taxonomy' => $taxonomy, 'slug' => $term, 'new' => $new];
                            if ($new) {
                                $newResources[$resourceKey] = true;
                            }
                        }
                    }
                    if (in_array($column, ['gallery', 'variant_image', 'description_bard'], true)) {
                        $paths = $column === 'description_bard' ? ProductDescription::images($value ?? []) : (array) $value;
                        foreach ($paths as $path) {
                            $staged = $images[$path] ?? null;
                            $new = $media->image($path, $staged, $user);
                            $resourceKey = 'image:'.$path;
                            $resources[$resourceKey] = ['path' => $path, 'staged' => $staged, 'new' => $new];
                            if ($new) {
                                $newResources[$resourceKey] = true;
                            }
                        }
                    }
                    if ($isProductField) {
                        $field = $column === 'description_bard' ? 'sellissimo_description' : 'sellissimo_'.$column;
                        $data[$field] = $value;
                        if (! $isVariant) {
                            $target[$field] = $value;
                        }
                    } else {
                        $target[$column === 'attributes' ? 'attributes' : 'image'] = $value;
                    }
                }
                $group['resources'] = $resources;
                $group['skus'][] = $sku;
                $stockChanged = false;
                if ($stockCell !== '') {
                    if (! $user->can('manage sellissimo inventory')) {
                        $this->invalid('Inventory permission is required to import warehouse stock.');
                    }
                    if (($data['sellissimo_service'] ?? $entry?->value('sellissimo_service')) || filled($target[$prefix.'download'] ?? null)
                        || ($data['sellissimo_track_stock'] ?? $entry?->value('sellissimo_track_stock')) === false) {
                        $this->invalid('Only tracked physical SKUs can import stock counts.');
                    }
                    $locations ??= app(Store::class)->table('locations')->pluck('code')->all();
                    $counts = $this->structuredValue('stock', $stockCell);
                    $total = app(Store::class)->table('stock')->where('sku', $sku)->value('available');
                    $balances = $inventory->balances($sku, (int) $total);
                    foreach ($counts as $location => $qty) {
                        if (! in_array($location, $locations, true)) {
                            $this->invalid("Unknown warehouse $location.");
                        }
                        $previous = $balances[$location] ?? 0;
                        if ($previous !== $qty || $total === null) {
                            $group['counts'][$sku][$location] = ['previous' => $previous, 'quantity' => $qty];
                            $stockChanged = true;
                        }
                    }
                }
                if ($isVariant) {
                    if ($index === null) {
                        $variants[] = $target;
                    } else {
                        $variants[$index] = $target;
                    }
                    $data['sellissimo_variants'] = $variants;
                } else {
                    $data = $target;
                }
                if (($entry?->published() || $group['published']) && ! $user->can('publish', $entry ?? Entry::make()->collection($handle)->locale($site))) {
                    $this->invalid('Publishing permission is required to update live products.');
                }
                $read = fn ($key) => array_key_exists($key, $data) ? $data[$key] : $entry?->value($key);
                if (filled($read('sellissimo_unit')) !== filled($read('sellissimo_unit_quantity'))) {
                    $this->invalid('Enter both the comparison unit and pack quantity.');
                }
                $starts = $read('sellissimo_sale_starts');
                $ends = $read('sellissimo_sale_ends');
                if ($starts && $ends && $starts > $ends) {
                    $this->invalid('Sale end precedes its start.');
                }
                $group['data'] = $data;
                $groups[$slug] = $group;
                $action = ! $existingSku ? ($isVariant ? 'Create variant' : 'Create product') : ($previousData === $data && ! $stockChanged && $wasPublished === $group['published'] ? 'Unchanged' : 'Update');
                $rows[] = compact('number', 'sku', 'action') + ['resources' => array_keys($newResources),
                    'stock' => $group['counts'][$sku] ?? []];
            } catch (ValidationException $e) {
                $issues++;
                $rows[] = ['number' => $number, 'sku' => $sku, 'action' => 'Conflict', 'message' => collect($e->errors())->flatten()->first()];
            }
        }

        return ['groups' => $groups, 'rows' => $rows, 'issues' => $issues, 'fingerprint' => hash('sha256', serialize([$groups, $rows]))];
    }

    private function structuredValue(string $column, string $cell): mixed
    {
        if ($cell === '__CLEAR__' && $column !== 'stock') {
            return null;
        }
        if ($column === 'variant_image') {
            return CatalogImportMedia::path($cell);
        }
        if ($column === 'description_bard') {
            $document = json_decode($cell, true, 64);
            if (! is_array($document) || ! str_starts_with(ltrim($cell), '[')) {
                $this->invalid('Enter a Bard JSON list in description_bard.');
            }

            return $document;
        }
        $decoded = json_decode($cell);
        if (in_array($column, ['attributes', 'stock'], true)) {
            if (! is_object($decoded) || count((array) $decoded) > 100) {
                $this->invalid("Enter a JSON object for $column.");
            }
            foreach ((array) $decoded as $key => $value) {
                if (blank($key) || strlen($key) > 100 || ($column === 'stock' ? ! is_int($value) || $value < 0 || $value > 999999999 : ! is_string($value) || blank($value) || mb_strlen($value) > 200)) {
                    $this->invalid("Invalid key or value in $column.");
                }
            }

            return (array) $decoded;
        }
        if (! is_array($decoded) || count($decoded) > ($column === 'gallery' ? 16 : 100)
            || array_filter($decoded, fn ($value) => ! is_string($value) || blank($value)) || count(array_unique($decoded)) !== count($decoded)) {
            $this->invalid("Enter a JSON list of distinct values for $column.");
        }

        return $decoded;
    }

    private function fieldValue(string $key, string $value): mixed
    {
        if (in_array($key, ['service', 'featured'], true)) {
            if (! in_array($value, ['0', '1'], true)) {
                $this->invalid("$key accepts 0 or 1.");
            }

            return $value === '1';
        }
        if (in_array($key, ['weight_kg', 'length_cm', 'width_cm', 'height_cm', 'unit_quantity'], true)) {
            if (! preg_match('/^[0-9]{1,9}(?:\.[0-9]{1,6})?$/D', $value) || ($key === 'unit_quantity' && (float) $value <= 0)) {
                $this->invalid("Enter a valid quantity for $key.");
            }

            return (float) $value;
        }
        if (in_array($key, ['sale_starts', 'sale_ends'], true)) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if (! $date || $date->format('Y-m-d') !== $value) {
                $this->invalid("Use YYYY-MM-DD in $key.");
            }
        }
        if ($key === 'unit' && ! in_array($value, ['kg', 'l', 'm', 'item'], true)) {
            $this->invalid('Choose kg, l, m or item for unit.');
        }
        if ($key === 'hs_code' && ! preg_match('/^[0-9]{6,12}$/D', $value)) {
            $this->invalid('Enter a customs code with 6 to 12 digits.');
        }
        if ($key === 'country_of_origin' && ! array_key_exists($value, StoreCountries::labels())) {
            $this->invalid('Use a two-letter country code for country_of_origin.');
        }

        return $value === "'__CLEAR__" ? '__CLEAR__' : $value;
    }

    public function apply(array $plan, string $handle, string $site): void
    {
        abort_if($plan['issues'], 422, 'Resolve all conflicts before importing.');
        $written = [];
        try {
            foreach ($plan['groups'] as $group) {
                if ($group['id'] && $group['data'] === $group['before'] && $group['published'] === $group['original_published']) {
                    continue;
                }
                $entry = $group['id'] ? Entry::find($group['id']) : Entry::make()->id($group['new_id'] ?? (string) Str::uuid())->collection($handle)->locale($site)->blueprint('sellissimo_product')->slug($group['slug']);
                if (! $entry || ($group['id'] && ($entry->data()->all() !== $group['before'] || $entry->published() !== $group['original_published']))) {
                    $this->invalid('This product changed after preview. Preview the CSV again.');
                }
                $written[$entry->id()] = $group;
                if ($entry->data($group['data'])->published($group['published'])->save() === false) {
                    $this->invalid('The site declined to save '.$group['slug'].'.');
                }
            }
        } catch (\Throwable $error) {
            // Entries may live in files. Restore matching writes without reverting independent edits.
            foreach (array_reverse($written, true) as $id => $group) {
                $entry = Entry::find($id);
                if (! $entry || $entry->data()->all() !== $group['data'] || $entry->published() !== $group['published']) {
                    continue;
                }
                if ($group['id']) {
                    $entry->data($group['before'])->published($group['original_published'])->saveQuietly();
                } else {
                    $entry->deleteQuietly();
                }
            }
            app(Catalog::class)->rebuildIndex();
            throw $error;
        }
    }
}
