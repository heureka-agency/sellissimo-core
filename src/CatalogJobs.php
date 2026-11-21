<?php

namespace Sellissimo\Core;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

class CatalogJobs
{
    public function __construct(private Store $store, private CatalogImport $imports, private CatalogImportMedia $media, private Inventory $inventory) {}

    public function stage($file, string $site, string $handle, $user): string
    {
        $id = (string) Str::uuid();
        try {
            [$parts, $images] = $this->media->unpack($file, $id);
            $plan = $this->imports->plan($parts, $site, $handle, $user, $images);
            $this->store->db()->transaction(function () use ($id, $site, $handle, $user, $plan) {
                $this->store->table('catalog_jobs')->insert(['id' => $id, 'staff' => $user->id(), 'site' => $site, 'collection' => $handle,
                    'rows' => count($plan['rows']), 'issues' => $plan['issues'], 'created_at' => now()]);
                foreach (array_chunk($plan['rows'], 200) as $rows) {
                    $this->store->table('catalog_job_rows')->insert(array_map(fn ($row) => ['job' => $id, 'details' => json_encode($row, JSON_THROW_ON_ERROR)], $rows));
                }
                foreach ($plan['groups'] as $group) {
                    if (! $group['id']) {
                        $group['new_id'] = (string) Str::uuid();
                    }
                    $this->store->table('catalog_job_groups')->insert(['job' => $id, 'plan' => json_encode($group, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)]);
                }
            });
        } catch (\Throwable $error) {
            CatalogImportMedia::storage()->deleteDirectory("sellissimo-imports/$id");
            throw $error;
        }

        return $id;
    }

    private function owned(string $id, $user)
    {
        return $this->store->table('catalog_jobs')->where('id', $id)->where('staff', $user->id())
            ->whereIn('site', StaffSites::allowed($user)->keys()->all());
    }

    public function preview(string $id, $user, int $page): ?array
    {
        if (! $job = $this->owned($id, $user)->first()) {
            return null;
        }
        $groups = $this->store->table('catalog_job_groups')->where('job', $id);
        $pages = max(1, (int) ceil($job->rows / 100));
        $page = min(max(1, $page), $pages);

        return ['token' => $id, 'rows' => $this->store->table('catalog_job_rows')->where('job', $id)->orderBy('id')->forPage($page, 100)
            ->pluck('details')->map(fn ($row) => json_decode($row, true)), 'issues' => $job->issues, 'total' => $job->rows,
            'site' => $job->site, 'collection' => $job->collection, 'complete' => (bool) $job->completed_at, 'page' => $page, 'pages' => $pages,
            'remaining' => (clone $groups)->where('applied', false)->count(), 'groups' => $groups->count()];
    }

    public function apply(string $id, $user): bool
    {
        $job = $this->owned($id, $user)->firstOrFail();
        abort_if($job->issues, 422, 'Resolve the preview conflicts before importing.');
        if ($job->completed_at) {
            return true;
        }
        $collection = Collection::findByHandle($job->collection);
        abort_unless($collection && in_array($job->collection, Catalog::collections(), true) && $collection->sites()->contains($job->site), 422);

        // Shared SKUs, terms and asset names span sites. Serialize batches, and check every affected live product.
        return Cache::lock('sellissimo.catalog-import', 600)->block(5, function () use ($id, $job, $user, $collection) {
            $pending = $this->store->table('catalog_job_groups')->where('job', $id)->where('applied', false);
            $batch = (clone $pending)->orderBy('id')->limit(100)->get();
            $this->store->db()->transaction(function () use ($batch, $user, $collection, $job) {
                $owners = [];
                foreach (Entry::query()->whereIn('collection', Catalog::collections())->where('site', $job->site)->get() as $ownedEntry) {
                    foreach ($ownedEntry->value('sellissimo_variants') ?: [['sku' => $ownedEntry->value('sellissimo_sku')]] as $variant) {
                        if (filled($variant['sku'] ?? null)) {
                            $owners[strtoupper(trim($variant['sku']))][] = $ownedEntry->id();
                        }
                    }
                }
                $groups = [];
                foreach ($batch as $record) {
                    $group = json_decode($record->plan, true, flags: JSON_THROW_ON_ERROR);
                    $entry = Entry::find($group['id'] ?: $group['new_id']);
                    if ($entry && ($entry->collectionHandle() !== $job->collection || $entry->locale() !== $job->site || $entry->slug() !== $group['slug'])) {
                        CatalogImportMedia::invalid('A product moved or its handle changed. Preview again.');
                    }
                    foreach ($group['skus'] ?? [] as $sku) {
                        if (array_diff($owners[$sku] ?? [], [$entry?->id()])) {
                            CatalogImportMedia::invalid("SKU $sku is now in use by another product. Preview again.");
                        }
                    }
                    if (! $entry && ! $group['id'] && Entry::query()->where('collection', $job->collection)->where('site', $job->site)->where('slug', $group['slug'])->first()) {
                        CatalogImportMedia::invalid('A product handle is now in use. Preview again.');
                    }
                    $allowed = $entry ? $user->can('edit', $entry)
                        : $user->can('store', [EntryContract::class, $collection, Site::get($job->site)]);
                    abort_unless($allowed && (! ($group['published'] || $group['original_published'])
                        || $user->can('publish', $entry ?? Entry::make()->collection($collection)->locale($job->site))), 403);
                    // A file-backed entry may have been saved immediately before a process interruption.
                    if ($entry && $entry->data()->all() === $group['data'] && $entry->published() === $group['published']) {
                        $group['id'] = $entry->id();
                        $group['before'] = $group['data'];
                        $group['original_published'] = $group['published'];
                    } elseif (($group['id'] && (! $entry || $entry->data()->all() !== $group['before'] || $entry->published() !== $group['original_published']))
                        || (! $group['id'] && $entry)) {
                        CatalogImportMedia::invalid('A product changed after preview. Preview the remaining changes again.');
                    }
                    $groups[] = $group;
                }
                // Validate all referenced resources and stock snapshots before publishing shared assets.
                foreach ($groups as $group) {
                    foreach ($group['resources'] ?? [] as $resource) {
                        if (isset($resource['taxonomy'])) {
                            $this->media->term($resource['taxonomy'], $resource['slug'], $job->site, $user);
                        } else {
                            $this->media->image($resource['path'], $resource['staged'], $user);
                        }
                    }
                    foreach ($group['counts'] ?? [] as $sku => $counts) {
                        abort_unless($user->can('manage sellissimo inventory'), 403);
                        ksort($counts);
                        foreach ($counts as $location => $count) {
                            $this->inventory->countLocation($sku, $location, $count['quantity'], $count['previous'], 'Catalog stock import', $user->email());
                        }
                    }
                }
                foreach ($groups as $group) {
                    foreach ($group['resources'] ?? [] as $resource) {
                        if (isset($resource['taxonomy'])) {
                            $this->media->term($resource['taxonomy'], $resource['slug'], $job->site, $user, true);
                        } else {
                            $this->media->image($resource['path'], $resource['staged'], $user, true);
                        }
                    }
                }
                $this->imports->apply(['issues' => 0, 'groups' => $groups], $job->collection, $job->site);
                $this->store->table('catalog_job_groups')->whereIn('id', $batch->pluck('id'))->update(['applied' => true]);
            });
            if ($pending->exists()) {
                return false;
            }
            $this->owned($id, $user)->update(['completed_at' => now()]);
            CatalogImportMedia::storage()->deleteDirectory("sellissimo-imports/$id");

            return true;
        });
    }

    public function prune(): void
    {
        if (! $this->store->hasTable('catalog_jobs')) {
            return;
        }
        foreach ($this->store->table('catalog_jobs')->where('created_at', '<', now()->subDays(7))->select('id')->lazyById(100) as $job) {
            $this->store->db()->transaction(function () use ($job) {
                foreach (['catalog_job_rows', 'catalog_job_groups'] as $table) {
                    $this->store->table($table)->where('job', $job->id)->delete();
                }
                $this->store->table('catalog_jobs')->where('id', $job->id)->delete();
            });
            CatalogImportMedia::storage()->deleteDirectory("sellissimo-imports/{$job->id}");
        }
    }
}
