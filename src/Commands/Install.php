<?php

namespace Sellissimo\Core\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Sellissimo\Core\Catalog;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

class Install extends Command
{
    protected $signature = 'sellissimo:install {--collection= : Create or connect this collection handle} {--blueprint=* : Attach commerce fields to collection.blueprint}';

    protected $description = 'Set up Sellissimo storage and a product blueprint';

    public function handle(): int
    {
        $config = config_path('sellissimo.php');
        $handles = $this->option('collection') ? [$this->option('collection')] : Catalog::collections();
        $blueprints = [];
        foreach ($handles as $handle) {
            if (! is_string($handle) || ! preg_match('/^[a-z][a-z0-9_]*$/D', $handle) || (is_file($config) && ! in_array($handle, Catalog::collections(), true))) {
                $this->error('Choose a collection handle listed in the Sellissimo configuration.');
                return self::FAILURE;
            }
            $blueprint = Blueprint::make('sellissimo_product')->setNamespace("collections.$handle");
            if (is_file($blueprint->path()) && ! str_contains(File::get($blueprint->path()), 'sellissimo::product')) {
                $this->error("A different sellissimo_product blueprint already exists in $handle.");
                return self::FAILURE;
            }
            $blueprints[$handle] = $blueprint;
        }
        $attachments = [];
        foreach ($this->option('blueprint') as $target) {
            if (! preg_match('/^([a-z][a-z0-9_]*)\\.([a-z][a-z0-9_]*)$/D', $target, $parts)
                || ! isset($blueprints[$parts[1]]) || ! ($existing = Blueprint::find("collections.$target")) || ! is_file($existing->path())) {
                $this->error("Choose an existing blueprint in a configured product collection: $target");
                return self::FAILURE;
            }
            if (! str_contains(File::get($existing->path()), 'sellissimo::product')) {
                $fields = $existing->fields()->all();
                if ($fields->keys()->contains(fn ($key) => str_starts_with($key, 'sellissimo_'))) {
                    $this->error("Review existing Sellissimo fields in $target before importing the fieldset.");
                    return self::FAILURE;
                }
                $attachments[$target] = $existing;
            }
        }
        $bundledSites = Site::all()->filter(fn ($site) => \Sellissimo\Core\StoreSettings::forSection($site->handle(), 'checkout', 'storefront', config('sellissimo.storefront')))->keys()->all();
        if ($bundledSites) {
            $prefix = trim(config('sellissimo.prefix'), '/');
            if (! $prefix || ! preg_match('~^[a-z0-9_-]+(?:/[a-z0-9_-]+)*$~D', $prefix)) {
                $this->error('Invalid storefront prefix.');

                return self::FAILURE;
            }
            foreach (Route::getRoutes() as $route) {
                if (! str_starts_with($route->getName() ?? '', 'sellissimo.') && ($route->uri() === $prefix || str_starts_with($route->uri(), $prefix.'/'))) {
                    $this->error('Storefront route collision: '.$route->uri());

                    return self::FAILURE;
                }
            }
            foreach (Entry::query()->whereIn('site', $bundledSites)->get() as $entry) {
                $uri = trim((string) $entry->uri(), '/');
                if ($uri === $prefix || str_starts_with($uri, $prefix.'/')) {
                    $this->error('Storefront collides with entry: '.$uri);

                    return self::FAILURE;
                }
            }
        }

        $connection = config('sellissimo.connection');
        if (config("database.connections.$connection.driver") === 'sqlite') {
            $path = config("database.connections.$connection.database");
            if ($path !== ':memory:' && ! is_file($path)) {
                File::ensureDirectoryExists(dirname($path));
                touch($path);
            }
        }
        $status = $this->call('migrate', ['--database' => $connection, '--path' => realpath(__DIR__.'/../../database/migrations'), '--realpath' => true, '--force' => true]);
        if ($status !== self::SUCCESS) {
            return $status;
        }
        if (! is_file($config)) {
            $source = File::get(__DIR__.'/../../config/sellissimo.php');
            $handle = $handles[0];
            File::put($config, str_replace("'SELLISSIMO_COLLECTION', 'products'", "'SELLISSIMO_COLLECTION', '$handle'", $source));
        }
        if (! AssetContainer::find('sellissimo_products')) {
            AssetContainer::make('sellissimo_products')->title('Product images')->disk('sellissimo_products')->save();
        }
        $taxonomies = ['sellissimo_categories' => 'Product categories', 'sellissimo_tags' => 'Product tags'];
        foreach ($taxonomies as $key => $title) {
            if (! Taxonomy::findByHandle($key)) {
                Taxonomy::make($key)->title($title)->sites(Site::all()->keys()->all())->save();
            }
        }
        $titleField = ['handle' => 'title', 'field' => ['type' => 'text', 'display' => 'Title', 'localizable' => true, 'validate' => ['required']]];
        $legacy = ['title' => 'Sellissimo product', 'tabs' => ['main' => ['sections' => [['fields' => [$titleField, ['import' => 'sellissimo::product']]]]]]];
        foreach ($blueprints as $handle => $blueprint) {
            $collection = Collection::findByHandle($handle) ?? Collection::make($handle)->title(str($handle)->headline()->toString())->sites(Site::all()->keys()->all());
            $existing = $collection->taxonomies()->map->handle()->values()->all();
            if (array_diff(array_keys($taxonomies), $existing)) {
                $collection->taxonomies(array_values(array_unique([...$existing, ...array_keys($taxonomies)])))->save();
            }
            if (! is_file($blueprint->path()) || Blueprint::find("collections.$handle.sellissimo_product")?->contents() == $legacy) {
                $tabs = [];
                foreach (['content' => 'Product details', 'sales' => 'Pricing and variants', 'shipping' => 'Shipping', 'discovery' => 'Merchandising'] as $key => $label) {
                    $fields = $key === 'content' ? [$titleField] : [];
                    $fields[] = ['import' => 'sellissimo::product_'.$key];
                    $tabs[$key === 'content' ? 'main' : $key] = ['display' => $label, 'sections' => [['fields' => $fields]]];
                }
                $blueprint->setContents(['title' => 'Sellissimo product', 'tabs' => $tabs])->save();
            }
        }
        foreach ($attachments as $blueprint) {
            $contents = $blueprint->contents();
            $tab = array_key_first($contents['tabs']) ?? 'main';
            $contents['tabs'][$tab]['sections'][] = ['display' => 'Commerce', 'fields' => [['import' => 'sellissimo::product']]];
            $blueprint->setContents($contents)->save();
        }
        app(Catalog::class)->rebuildIndex();
        $provider = app(\Sellissimo\Core\Search\StoreRecords::class);
        foreach (\Statamic\Facades\Search::indexes() as $index) {
            if ($index->handle() === 'cp' && $index->exists() && $index->shouldIndex(new \Sellissimo\Core\Search\StoreRecord('order', (object) []))) {
                $index->insertMultiple($provider->provide());
            }
        }
        $this->info('Sellissimo is ready in '.implode(', ', $handles).'. Choose a selling currency in Store → Settings.');

        return self::SUCCESS;
    }
}
