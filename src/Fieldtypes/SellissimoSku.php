<?php

namespace Sellissimo\Core\Fieldtypes;

use Sellissimo\Core\Catalog;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\User;
use Statamic\Fieldtypes\Text;

class SellissimoSku extends Text
{
    protected static $title = 'Sellissimo SKU';

    public function preload()
    {
        $entry = $this->field()->parent();
        $sku = strtoupper(trim((string) $this->field()->value()));
        if (! $sku || ! $entry instanceof Entry || ! in_array($entry->collectionHandle(), Catalog::collections(), true)
            || ! User::current()?->can('manage sellissimo inventory') || $entry->value('sellissimo_service') || $entry->value('sellissimo_track_stock') === false) {
            return [];
        }

        $variants = $entry->value('sellissimo_variants') ?? [];
        $row = $variants ? ($variants[$this->field()->parentIndex()] ?? []) : [
            'sku' => $entry->value('sellissimo_sku'), 'download' => $entry->value('sellissimo_download'),
        ];
        if ($sku !== strtoupper(trim((string) ($row['sku'] ?? ''))) || filled($row['download'] ?? null)) {
            return [];
        }

        return ['stockSku' => $sku, 'stockUrl' => cp_route('sellissimo.inventory', ['site' => $entry->locale(), 'q' => $sku, 'adjust' => $sku])];
    }
}
