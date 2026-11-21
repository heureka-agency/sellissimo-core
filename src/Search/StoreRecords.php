<?php

namespace Sellissimo\Core\Search;

use Illuminate\Support\Collection;
use Sellissimo\Core\Store;
use Statamic\Facades\Search;
use Statamic\Search\Searchables\Provider;

final class StoreRecords extends Provider
{
    protected static $handle = 'sellissimo';
    protected static $referencePrefix = 'sellissimo';

    public function provide(): Collection
    {
        $store = app(Store::class);
        $db = $store->db();
        if ($db->getDriverName() === 'sqlite' && $db->getDatabaseName() !== ':memory:' && ! is_file($db->getDatabaseName())) {
            return collect();
        }
        if (! $db->getSchemaBuilder()->hasTable('sellissimo_orders')) {
            return collect();
        }
        return $store->table('orders')->pluck('id')->map(fn ($id) => "sellissimo::order/$id")
            ->concat($store->table('discounts')->pluck('code')->map(fn ($code) => "sellissimo::discount/$code"));
    }

    public function find(array $keys): Collection
    {
        $records = collect();
        foreach (['order' => ['orders', 'id'], 'discount' => ['discounts', 'code']] as $kind => [$table, $key]) {
            $ids = collect($keys)->filter(fn ($reference) => str_starts_with($reference, $kind.'/'))
                ->map(fn ($reference) => substr($reference, strlen($kind) + 1));
            if ($ids->isNotEmpty()) {
                $fields = $kind === 'order' ? ['id', 'order_number', 'site'] : ['code', 'site'];
                $records = $records->concat(app(Store::class)->table($table)->whereIn($key, $ids)->get($fields)
                    ->map(fn ($row) => new StoreRecord($kind, $row)));
            }
        }
        return $records;
    }

    public function contains($searchable): bool
    {
        return $searchable instanceof StoreRecord;
    }

    public static function changed(string $key): void
    {
        app(Store::class)->db()->afterCommit(function () use ($key) {
            // A new index will include all records when Statamic first builds it.
            foreach (Search::indexes() as $index) {
                if ($index->handle() === 'cp' && $index->exists() && $index->shouldIndex(new StoreRecord(strtok($key, '/'), (object) []))) {
                    $index->insert('sellissimo::'.$key);
                }
            }
        });
    }
}
