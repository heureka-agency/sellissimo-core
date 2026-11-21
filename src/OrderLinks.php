<?php

namespace Sellissimo\Core;

use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

class OrderLinks
{
    public static function signed(object $order, string $route, DateTimeInterface $expires, array $parameters): string
    {
        if ($revision = $order->contact_revision ?? 0) {
            $parameters['contact_revision'] = (int) $revision;
        }

        return URL::temporarySignedRoute($route, $expires, $parameters);
    }

    public static function valid(Request $request, object $order): bool
    {
        return $request->hasValidSignature()
            && (string) ($order->contact_revision ?? 0) === $request->query('contact_revision', '0');
    }
}
