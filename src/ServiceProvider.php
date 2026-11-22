<?php

namespace Sellissimo\Core;

use Illuminate\Auth\Events\Login;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Statamic\Events\CollectionSaved;
use Statamic\Events\EntryDeleted;
use Statamic\Events\EntrySaved;
use Statamic\Events\EntrySaving;
use Statamic\Events\TermDeleted;
use Statamic\Events\TermSaved;
use Statamic\Facades\CP\Nav;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $viewNamespace = 'sellissimo';

    protected $config = false;

    protected $vite = [
        'publicDirectory' => 'resources/dist',
        'input' => ['resources/js/cp.js'],
    ];

    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sellissimo.php', 'sellissimo');
        if (! config()->has('database.connections.sellissimo')) {
            config(['database.connections.sellissimo' => [
                'driver' => 'sqlite', 'database' => config('sellissimo.sqlite_path'),
                'foreign_key_constraints' => true, 'busy_timeout' => 10000, 'transaction_mode' => 'IMMEDIATE',
            ]]);
        }
        if (! config()->has('filesystems.disks.sellissimo_return_labels')) {
            config(['filesystems.disks.sellissimo_return_labels' => [
                'driver' => 'local', 'root' => storage_path('app/sellissimo/return-labels'), 'visibility' => 'private', 'throw' => true,
            ]]);
        }
        if (! config()->has('filesystems.disks.sellissimo_private')) {
            config(['filesystems.disks.sellissimo_private' => [
                'driver' => 'local', 'root' => storage_path('app/sellissimo/private'), 'throw' => true,
            ]]);
        }
        if (! config()->has('filesystems.disks.sellissimo_products')) {
            config(['filesystems.disks.sellissimo_products' => [
                'driver' => 'local', 'root' => public_path('assets/sellissimo'), 'url' => rtrim(config('app.url'), '/').'/assets/sellissimo',
                'visibility' => 'public', 'throw' => true,
            ]]);
        }
        config(['statamic.stache.stores.entries.indexes.sellissimo_skus' => StacheSkuIndex::class]);
        $this->app->singleton(Registry::class);
        $this->app->scoped(StoreSettings::class);
        $this->app->singleton(SettingsRegistry::class);
    }

    public function bootAddon()
    {
        $this->app['events']->listen([
            TransactionBeginning::class,
            TransactionCommitted::class,
            TransactionRolledBack::class,
        ], StoreSettings::transactionChanged(...));
        $this->loadJsonTranslationsFrom(__DIR__.'/../resources/lang');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'sellissimo');
        $this->publishes([__DIR__.'/../resources/lang' => lang_path('vendor/sellissimo')], 'sellissimo-translations');
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        View::composer('sellissimo::shop-merchandising', function ($view) {
            $merchandising = app(Merchandising::class);
            $site = $view->getData()['site'];
            $view->with(['featuredProducts' => $merchandising->featured($site), 'popularProducts' => $merchandising->popular($site)]);
        });
        View::composer('sellissimo::product-merchandising', function ($view) {
            $merchandising = app(Merchandising::class);
            ['site' => $site, 'product' => $product] = $view->getData();
            if (! array_intersect(['cross_sell', 'upsell'], StoreFeatures::get($site)['features'])) {
                $view->with(['upsellProducts' => [], 'crossSellProducts' => []]);

                return;
            }
            $excluded = $merchandising->cartEntryIds($site);
            $upgrades = $merchandising->recommend($site, 'upsell', [$product['sku']], excludeIds: $excluded);
            $view->with([
                'upsellProducts' => $upgrades,
                'crossSellProducts' => $merchandising->recommend($site, 'cross_sell', [$product['sku']], excludeIds: [...$excluded, ...array_column($upgrades, 'entry_id')]),
            ]);
        });
        View::composer('sellissimo::cart-merchandising', function ($view) {
            ['site' => $site, 'receipt' => $receipt] = $view->getData();
            $view->with('crossSellProducts', app(Merchandising::class)->recommend($site, 'cross_sell', array_column($receipt['lines'] ?? [], 'sku')));
        });
        $this->publishes([__DIR__.'/../config/sellissimo.php' => config_path('sellissimo.php')], 'sellissimo-config');
        $excluded = config('statamic.static_caching.exclude.urls', []);
        $excluded[] = '/api/sellissimo/*';
        $prefix = '/'.trim(config('sellissimo.prefix'), '/');
        if ($prefix !== '/') {
            $excluded[] = $prefix;
            $excluded[] = $prefix.'/*';
        }
        config(['statamic.static_caching.exclude.urls' => array_values(array_unique($excluded))]);
        $this->app['events']->listen('sellissimo.order.paid', function (string $id) {
            try {
                app(Receipts::class)->send($id);
                foreach (app(Store::class)->table('vouchers')->where('purchase_id', $id)->whereNull('sent_at')->pluck('id') as $voucherId) {
                    app(GiftVouchers::class)->send($voucherId);
                }
            } catch (\Throwable $e) {
                report($e); // The scheduled reconciler retries unsent receipts.
            }
        });
        $this->app->make(Registry::class)->payment('pay_on_delivery', new PayOnDelivery);
        if ($this->app->environment(['local', 'testing'])) {
            $this->app->make(Registry::class)->payment('simulator', $this->app->make(SimulatedPayments::class));
        }
        $this->app['events']->listen(Login::class, function ($event) {
            $request = request();
            if ($request->hasSession() && $event->guard === config('statamic.users.guards.web', 'web')) {
                app(Wishlists::class)->merge($request, (string) $event->user->getAuthIdentifier());
                foreach (array_keys($request->session()->get('sellissimo.cart', [])) as $site) {
                    app(AccountCarts::class)->restore($request, $site, false, (string) $event->user->getAuthIdentifier());
                }
            }
        });
        $this->app['events']->listen(EntrySaving::class, [Catalog::class, 'validateEntry']);
        foreach ([EntrySaved::class, EntryDeleted::class] as $event) {
            $this->app['events']->listen($event, [Catalog::class, 'refreshEntry']);
        }
        foreach ([TermSaved::class, TermDeleted::class, CollectionSaved::class] as $event) {
            $this->app['events']->listen($event, function () {
                app(CatalogBrowse::class)->invalidate();
            });
        }
        Search\StoreRecords::register();
        \Statamic\Facades\Search::addCpSearchable(Search\StoreRecords::class);
        Gate::policy(Search\StoreRecord::class, Search\StoreRecordPolicy::class);
        StaffAccess::register();
        app(SettingsRegistry::class)->registerCoreLinks();
        $widgets = config('statamic.cp.widgets', []);
        if (config('sellissimo.activity_widget', true) && ! collect($widgets)->contains(fn ($widget) => ($widget['type'] ?? null) === 'sellissimo_activity')) {
            config(['statamic.cp.widgets' => [...$widgets, ['type' => 'sellissimo_activity', 'width' => 100]]]);
        }
        Nav::extend(StoreNavigation::build(...));
    }

    protected function schedule(Schedule $schedule)
    {
        $schedule->call(fn () => app(CatalogJobs::class)->prune())->name('sellissimo:prune-imports')->daily()->withoutOverlapping();
        $schedule->command('sellissimo:reconcile')->everyFiveMinutes()->withoutOverlapping();
        $schedule->call(SettingsStatus::heartbeat(...))->name('sellissimo:settings-heartbeat')->everyFiveMinutes();
    }
}
