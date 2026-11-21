<?php

namespace Sellissimo\Core;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Statamic\Facades\Permission;

class StaffAccess
{
    public const PERMISSIONS = [
        'manage sellissimo settings' => 'Configure the store',
        'manage sellissimo payment settings' => 'Configure store payment methods',
        'manage sellissimo payment credentials' => 'Manage payment account credentials',
        'manage sellissimo vouchers' => 'Manage gift vouchers',
        'correct sellissimo guest contacts' => 'Correct guest order emails',
        'edit sellissimo order fields' => 'Edit staff-only order fields',
        'view sellissimo returns' => 'View return requests',
        'view sellissimo orders' => 'View orders',
        'support sellissimo orders' => 'Support customers and manage downloads',
        'fulfill sellissimo orders' => 'Ship orders and receive returns',
        'refund sellissimo orders' => 'Issue refunds',
        'collect sellissimo payments' => 'Record or cancel pay-on-delivery orders',
        'export sellissimo orders' => 'Export order data',
        'manage sellissimo drafts' => 'Manage draft orders',
        'amend sellissimo orders' => 'Amend paid orders and settle balances',
        'manage sellissimo webhooks' => 'Manage outgoing webhooks',
        'manage sellissimo inventory' => 'Manage inventory',
        'manage sellissimo shipping' => 'Manage shipping zones and rates',
        'import sellissimo products' => 'Import products and variants',
        'manage sellissimo customers' => 'Manage customer address books',
        'manage sellissimo discounts' => 'Manage discounts',
        'manage sellissimo taxes' => 'Manage taxes',
        'view sellissimo sales' => 'View sales reports',
    ];

    public static function register(): void
    {
        Permission::group('sellissimo', 'Sellissimo', function () {
            Permission::register('manage sellissimo')->label(__('Manage Sellissimo store'));
            foreach (self::PERMISSIONS as $permission => $label) {
                Permission::register($permission)->label(__($label));
            }
        });
        Gate::before(function ($user, $ability) {
            if (! isset(self::PERMISSIONS[$ability])) {
                return null;
            }
            if ($user->can('manage sellissimo')) {
                return true;
            }
            if ($ability === 'view sellissimo returns' && $user->canAny(['refund sellissimo orders', 'fulfill sellissimo orders'])) {
                return true;
            }
            if ($ability === 'view sellissimo orders' && $user->canAny([
                'correct sellissimo guest contacts', 'edit sellissimo order fields', 'amend sellissimo orders', 'support sellissimo orders', 'fulfill sellissimo orders', 'refund sellissimo orders',
                'collect sellissimo payments', 'export sellissimo orders', 'manage sellissimo drafts',
            ])) {
                return true;
            }

            return null;
        });
    }

    public static function ability(string $route, ?string $resource = null): string
    {
        return match ($route) {
            'settings.currency', 'settings.currency.save' => 'manage sellissimo settings',
            'drafts.currency' => 'manage sellissimo drafts',
            'vouchers', 'vouchers.issue' => 'manage sellissimo vouchers',
            'contact' => 'correct sellissimo guest contacts',
            'fields' => 'edit sellissimo order fields',
            'amend', 'amend.products', 'amend.delivery', 'amend.artwork', 'amend.preview', 'amend.email', 'amend.retry' => 'amend sellissimo orders',
            'webhooks', 'webhooks.save', 'webhooks.retry' => 'manage sellissimo webhooks',
            'views.save', 'views.delete', 'dashboard', 'orders', 'order', 'artwork' => 'view sellissimo orders',
            'recovery', 'recovery.remind', 'note', 'resend', 'download-access' => 'support sellissimo orders',
            'address', 'labels', 'reassign', 'ship', 'delivered', 'receive', 'shipment-email', 'packing-slip', 'packing-slips' => 'fulfill sellissimo orders',
            'settle-delivery' => 'collect sellissimo payments',
            'tax-preferences' => 'manage sellissimo taxes',
            'refund', 'cancel', 'cancel.retry', 'payments.reconcile' => 'refund sellissimo orders',
            'export' => 'export sellissimo orders',
            'drafts', 'drafts.products', 'drafts.upload', 'drafts.customers', 'drafts.new', 'drafts.edit', 'drafts.save', 'drafts.quote', 'drafts.cancel' => 'manage sellissimo drafts',
            'import', 'import.export', 'import.template', 'import.preview', 'import.apply' => 'import sellissimo products',
            'customers','customer','customer.address','customer.invite' => 'manage sellissimo customers',
            'sales' => 'view sellissimo sales',
            'returns.policy' => 'manage sellissimo',
            'returns' => 'view sellissimo returns',
            'returns.refund', 'returns.balance' => 'refund sellissimo orders',
            'returns.action' => 'fulfill sellissimo orders',
            'inventory','locations','transfer','location.count','location.create', 'stock' => 'manage sellissimo inventory',
            'discount-accounts' => 'manage sellissimo discounts',
            'rules', 'rules.save' => $resource === 'discounts' ? 'manage sellissimo discounts' : 'manage sellissimo taxes',
        };
    }

    public function handle(Request $request, Closure $next)
    {
        $route = str($request->route()->getName())->after('statamic.cp.sellissimo.')->toString();
        Gate::authorize(self::ability($route, $request->route('resource')));

        abort_if(StaffSites::allowed()->isEmpty(), 403);
        $store = app(Store::class);
        $orderId = $request->route('order');
        if ($amendment = $request->route('amendment')) {
            $orderId = $store->table('amendments')->where('id', $amendment)->firstOrFail(['order_id'])->order_id;
        } elseif (in_array($route, ['returns.action', 'returns.refund', 'returns.balance'], true)) {
            $orderId = $store->table('return_requests')->where('id', $request->route('id'))->firstOrFail(['order_id'])->order_id;
        }
        if ($orderId) {
            StaffSites::authorize($store->table('orders')->where('id', $orderId)->value('site'));
        } elseif ($request->filled('site') && is_string($request->input('site'))) {
            StaffSites::authorize($request->input('site'));
        }
        if ($cart = $request->route('cart')) {
            StaffSites::authorize($store->table('carts')->where('id', $cart)->value('site'));
        }
        if (str_starts_with($route, 'webhooks')) {
            // Endpoints receive events for every site in the store.
            abort_unless(StaffSites::storewide(), 403);
        }

        return $next($request);
    }
}
