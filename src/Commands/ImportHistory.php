<?php

namespace Sellissimo\Core\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Sellissimo\Core\CargoHistory;
use Sellissimo\Core\Store;
use Statamic\Facades\Site;
use Symfony\Component\Yaml\Yaml;

class ImportHistory extends Command
{
    protected $signature = 'sellissimo:import-history {directory : Directory containing Cargo orders or discounts} {--site= : Site for files outside a site subdirectory} {--map= : JSON customer and product mappings} {--apply : Commit all records after validation}';

    protected $description = 'Preview or import Cargo orders, customer links and discounts';

    public function handle(Store $store, CargoHistory $import): int
    {
        $root = realpath($this->argument('directory'));
        if (! $root || ! is_dir($root)) {
            $this->error('Choose an existing Cargo content directory.');

            return self::FAILURE;
        }
        $apply = $this->option('apply');
        $location = $root;
        $store->db()->beginTransaction();
        try {
            $map = $this->option('map') ? json_decode(File::get($this->option('map')), true, flags: JSON_THROW_ON_ERROR) : [];
            $created = $skipped = 0;
            foreach (['orders', 'discounts'] as $type) {
                if (! is_dir("$root/$type")) {
                    continue;
                }
                foreach (File::allFiles("$root/$type") as $file) {
                    if (! in_array(strtolower($file->getExtension()), ['yaml', 'yml'], true)) {
                        continue;
                    }
                    $location = $type.'/'.$file->getRelativePathname();
                    $site = $file->getRelativePath() ?: ($this->option('site') ?: Site::default()->handle());
                    $data = Yaml::parseFile($file->getRealPath());
                    $key = 'cargo:'.$type.':'.$site.':'.($type === 'orders' ? ($data['id'] ?? '') : $file->getBasename('.'.$file->getExtension()));
                    $checksum = hash('sha256', serialize($data));
                    if ($old = $store->table('historical_imports')->where('source_key', $key)->first()) {
                        throw_unless(hash_equals($old->checksum, $checksum), 'This record changed after import. Review the existing record before importing it again.');
                        $skipped++;

                        continue;
                    }
                    $target = $type === 'orders' ? $import->order($data, $site, $file->getFilenameWithoutExtension(), $map)
                        : $import->discount($data, $site, $key, $map);
                    $store->table('historical_imports')->insert(['source_key' => $key, 'checksum' => $checksum, 'destination' => $target, 'source_record' => json_encode($data, JSON_THROW_ON_ERROR)]);
                    $created++;
                }
            }
            throw_unless($created + $skipped, 'No Cargo orders or discounts were found.');
            if ($apply) {
                $store->db()->commit();
            } else {
                $store->db()->rollBack();
            }
            $this->info(($apply ? 'Imported' : 'Previewed')." $created records. $skipped already imported.");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $store->db()->rollBack();
            $this->error($location.' — '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
