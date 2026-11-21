<?php

namespace Sellissimo\Core\Commands;

use Illuminate\Console\Command;
use Sellissimo\Core\Catalog;
use Statamic\Facades\Site;

class CatalogCheck extends Command
{
    protected $signature = 'sellissimo:catalog-check {--reindex : Rebuild lookup after importing content files}';
    protected $description = 'Check product collections for conflicting SKUs';

    public function handle(Catalog $catalog): int
    {
        if ($this->option('reindex')) {
            $catalog->rebuildIndex();
        }
        $conflicts = 0;
        foreach (Site::all()->keys() as $site) {
            foreach ($catalog->conflicts($site) as $sku => $rows) {
                $conflicts++;
                foreach ($rows as [$entry, $label]) {
                    $this->line("$site / $sku: {$entry->path()} ($label)");
                }
            }
        }
        $this->line("$conflicts conflicting SKUs.");

        return $conflicts ? self::FAILURE : self::SUCCESS;
    }
}
