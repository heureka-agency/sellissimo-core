<?php

namespace Sellissimo\Core\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\Router;
use Sellissimo\Core\OperationalSettings;
use Sellissimo\Core\Store;
use Statamic\Facades\Site;

final class StorefrontAvailability
{
    public function handle(Request $request, Closure $next)
    {
        if ($cartId = $request->route('cart')) {
            $store = app(Store::class);
            $site = $store->hasTable('carts') ? $store->table('carts')->where('id', $cartId)->value('site') : null;
        } else {
            $site = $request->input('site', Site::current()->handle());
        }
        if (is_string($site) && Site::all()->has($site) && OperationalSettings::storefront($site)) {
            return $next($request);
        }

        // A disabled bundled storefront must still let Statamic serve the site's own pages.
        $router = app(Router::class);
        $frontend = $router->getRoutes()->getByName('statamic.site');
        abort_unless($frontend && $frontend->matches($request), 404);
        $frontend = (clone $frontend)->bind($request);
        $existing = $router->gatherRouteMiddleware($request->route());
        $middleware = array_diff($router->gatherRouteMiddleware($frontend), $existing);
        $request->setRouteResolver(fn () => $frontend);
        return app(Pipeline::class)->send($request)->through($middleware)
            ->then(fn ($request) => $router->prepareResponse($request, $frontend->run()));
    }
}
