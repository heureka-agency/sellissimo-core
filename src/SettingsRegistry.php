<?php

namespace Sellissimo\Core;

use Closure;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;

final class SettingsRegistry
{
    public const MANAGE = 'manage sellissimo settings';

    private const SECTIONS = [
        'details' => ['Store details and currency', 'Business address, customer contact and selling currency.', 'Store setup', [self::MANAGE]],
        'catalog' => ['Catalog and inventory', 'Product collections, price coverage, stock levels and locations.', 'Store setup', [self::MANAGE, 'manage sellissimo inventory']],
        'checkout' => ['Checkout and customers', 'Storefront, destinations, account access and policy pages.', 'Store setup', [self::MANAGE]],
        'payments' => ['Payments', 'Payment methods, connected accounts and test orders.', 'Payments, shipping and taxes', ['manage sellissimo payment settings', 'manage sellissimo payment credentials']],
        'shipping' => ['Shipping and pickup', 'Delivery services, eligible destinations and quote previews.', 'Payments, shipping and taxes', [self::MANAGE, 'manage sellissimo shipping']],
        'taxes' => ['Taxes and pricing', 'Tax classes, zones, sample calculations and pricing rules.', 'Payments, shipping and taxes', [self::MANAGE, 'manage sellissimo taxes', 'manage sellissimo discounts']],
        'orders' => ['Orders, returns and downloads', 'Order numbering, return policies and download access.', 'Store operations', [self::MANAGE, 'view sellissimo returns']],
        'emails' => ['Emails and notifications', 'Sender details, customer messages, staff alerts and cart recovery.', 'Store operations', [self::MANAGE]],
        'system' => ['Integrations and system status', 'Installed providers, outgoing webhooks and delivery checks.', 'Store operations', [self::MANAGE, 'manage sellissimo webhooks']],
    ];

    private array $extensions = [];
    private array $actions = [];

    /** Field definitions use Statamic field configurations and Laravel validation rules. */
    public function section(string $handle, string $title, string $description, string $permission, Closure $fields, ?Closure $validate = null, ?Closure $readiness = null, ?string $route = null): void
    {
        if (!preg_match('/^[a-z][a-z0-9_-]{0,69}$/D', $handle) || $handle === 'currency' || isset(self::SECTIONS[$handle]) || isset($this->extensions[$handle])) {
            throw new InvalidArgumentException("Invalid or duplicate settings section: $handle");
        }
        $this->extensions[$handle] = compact('title', 'description', 'permission', 'fields', 'validate', 'readiness', 'route');
    }

    public function destinationRoute(string $handle): ?string
    {
        $route = $this->extensions[$handle]['route'] ?? null;
        return $route !== null && Route::has('statamic.cp.'.$route) ? $route : null;
    }

    public function action(string $section, string $title, string $route, string $permission, bool $shared = false, array $parameters = []): void
    {
        $this->actions[$section][] = compact('title', 'route', 'permission', 'shared', 'parameters');
    }

    public function sections(string $site): array
    {
        $sections = self::SECTIONS;
        foreach ($this->extensions as $handle => $ext) {
            $sections[$handle] = [$ext['title'], $ext['description'], 'Integrations', [$ext['permission']]];
        }
        $visible = [];
        foreach ($sections as $handle => [$label, $description, $group, $permissions]) {
            if ($handle === 'payments' && !StaffSites::storewide()) {
                $permissions = ['manage sellissimo payment settings'];
            }
            if ($handle === 'system' && !StaffSites::storewide()) {
                $permissions = [self::MANAGE];
            }
            if (!Gate::any($permissions)) {
                continue;
            }
            $route = $this->destinationRoute($handle) ?? ($handle === 'payments' ? 'sellissimo.settings.payments' : 'sellissimo.settings.section');
            $params = $route === 'sellissimo.settings.section' ? ['section' => $handle, 'site' => $site] : compact('site');
            $visible[$handle] = ['handle' => $handle, 'title' => __($label), 'description' => __($description),
                'group' => __($group), 'url' => cp_route($route, $params)];
        }

        return $visible;
    }

    public function fields(string $section, string $site): array
    {
        if (isset($this->extensions[$section])) {
            $fields = ($this->extensions[$section]['fields'])($site);
            return array_filter($fields, fn ($definition) => !isset($definition['permission']) || Gate::allows($definition['permission']));
        }
        $fields = StoreIdentity::fields($section, $site) + OperationalSettings::fields($section, $site);
        if (Gate::allows(self::MANAGE)) {
            return $fields;
        }

        return array_filter($fields, fn ($definition) => isset($definition['permission']) && Gate::allows($definition['permission']));
    }

    public function validate(string $section, string $site, array $changes): void
    {
        if (isset($this->extensions[$section])) {
            if ($validate = $this->extensions[$section]['validate']) {
                $validate($site, $changes);
            }
            return;
        }
        OperationalSettings::validateChanges($section, $site, $changes);
    }

    public function extensionChecks(string $section, string $site): array
    {
        $check = $this->extensions[$section]['readiness'] ?? null;

        return $check ? $check($site) : [];
    }

    public function catalogCreationLinks(string $site): array
    {
        $links = [];
        foreach (Catalog::collections() as $handle) {
            $collection = \Statamic\Facades\Collection::findByHandle($handle);
            $siteObject = \Statamic\Facades\Site::get($site);
            if ($collection && $collection->sites()->contains($site) && Gate::allows('create', [\Statamic\Contracts\Entries\Entry::class, $collection, $siteObject])) {
                $links[] = ['title' => __('Add a product to :collection', ['collection' => $collection->title()]), 'shared' => false, 'url' => $collection->createEntryUrl($site)];
            }
        }
        return $links;
    }

    public function links(string $section, string $site): array
    {
        $links = $section === 'catalog' ? $this->catalogCreationLinks($site) : [];
        foreach ($this->actions[$section] ?? [] as $action) {
            if (Gate::denies($action['permission']) || ($action['shared'] && !StaffSites::storewide()) || !Route::has('statamic.cp.'.$action['route'])) {
                continue;
            }
            $links[] = ['title' => __($action['title']), 'shared' => $action['shared'], 'url' => cp_route($action['route'], $action['parameters'] + ['site' => $site])];
        }

        return $links;
    }

    public function registerCoreLinks(): void
    {
        $this->action('catalog', 'Manage stock and locations', 'sellissimo.locations', 'manage sellissimo inventory');
        $this->action('catalog', 'Import or export catalog prices', 'sellissimo.import', 'import sellissimo products');
        $this->action('checkout', 'Wishlists, vouchers and back-in-stock alerts', 'sellissimo.features', self::MANAGE, false, ['from' => 'checkout']);
        $this->action('taxes', 'Tax classes and site preferences', 'sellissimo.rules', 'manage sellissimo taxes', false, ['resource' => 'tax-classes']);
        $this->action('taxes', 'Tax zones', 'sellissimo.rules', 'manage sellissimo taxes', false, ['resource' => 'tax-zones']);
        $this->action('taxes', 'Discounts', 'sellissimo.rules', 'manage sellissimo discounts', false, ['resource' => 'discounts']);
        $this->action('orders', 'Return requests and policy', 'sellissimo.returns', 'view sellissimo returns');
        $this->action('emails', 'Staff alert recipients by event', 'sellissimo.features', self::MANAGE, false, ['from' => 'emails']);
        $this->action('emails', 'Checkout recovery', 'sellissimo.recovery', 'support sellissimo orders');
        $this->action('system', 'Outgoing webhooks', 'sellissimo.webhooks', 'manage sellissimo webhooks', true);
    }
}
