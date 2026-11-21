<?php

namespace Sellissimo\Core\Http;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PrivateResponse
{
    /** Resolve the language from the cart or order that owns a buyer-facing page. */
    public static function forSite(string $handle): void
    {
        if ($site = \Statamic\Facades\Site::all()->get($handle)) {
            request()->attributes->set('sellissimo.storefront_site', $handle);
            app()->setLocale($site->shortLocale());
        }
    }

    public function handle(Request $request, Closure $next)
    {
        $locale = app()->getLocale();
        try {
            $response = $next($request);
        } finally {
            if (app()->getLocale() !== $locale) {
                app()->setLocale($locale);
            }
        }
        $error = $response->exception ?? null;
        if ($error instanceof HttpException && in_array($error->getStatusCode(), [409, 422], true)
            && $request->isMethod('post') && ! $request->is('api/*')
            && (! $request->expectsJson() || $request->header('X-Inertia'))) {
            $response = back()->withInput()->withErrors(['sellissimo' => $error->getMessage() ?: 'Unable to complete this change.']);
        }
        // Some hosts render every /api/* error as JSON, including the bundled buyer forms.
        if ($error instanceof ValidationException && $response instanceof JsonResponse && $request->isMethod('post')
            && $request->prefers(['application/json', 'text/html']) === 'text/html') {
            $form = match ($request->route()?->getName()) {
                'sellissimo.addresses', 'sellissimo.buyer.returns', 'sellissimo.draft.pay',
                'sellissimo.voucher.balance', 'sellissimo.amend.pay', 'sellissimo.invitation', 'sellissimo.claim' => $request->fullUrl(),
                'sellissimo.claim.request' => route('sellissimo.history', ['site' => $request->attributes->get('sellissimo.storefront_site')]),
                'sellissimo.receipt.recover.send' => route('sellissimo.receipt.recover', ['site' => $request->attributes->get('sellissimo.storefront_site')]),
                default => null,
            };
            if ($form) {
                $response = redirect($form)->withErrors($error->validator, $error->errorBag)
                    ->withInput($request->except(['password', 'password_confirmation', 'current_password', '_token', 'voucher']));
            }
        }
        $response->headers->set('Cache-Control', 'private, no-store');
        // Inertia uses the current CP URL to return merchants to the same filtered view.
        $response->headers->set('Referrer-Policy', $request->routeIs('statamic.cp.sellissimo.*', 'statamic.cp.sellissimo-shipping.*') ? 'same-origin' : 'no-referrer');

        return $response;
    }
}
