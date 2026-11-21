<?php

namespace Sellissimo\Core;

class OrderNumber
{
    /** Allocate inside the same transaction that creates the order. */
    public static function allocate(Store $store): int
    {
        $sequence = $store->table('order_sequences')->where('name', 'orders');
        $last = (int) $sequence->lockForUpdate()->value('last_number');
        $next = max(1, (int) OperationalSettings::shared('first_order_number', 'first_order_number', 1000), $last + 1);
        $sequence->update(['last_number' => $next]);

        return $next;
    }

    public static function display(object $order): string
    {
        return '#'.($order->order_number ?? strtoupper(substr($order->id, 0, 8)));
    }

    public static function findForBuyer(Store $store, string $site, string $email, string $reference, bool $acceptId = false): ?object
    {
        $reference = strtoupper(ltrim(trim($reference), '#'));
        $query = $store->table('orders')->where('site', $site)->whereRaw('lower(email) = ?', [mb_strtolower(trim($email))]);
        if ($acceptId && \Illuminate\Support\Str::isUuid($reference)) {
            return $query->where('id', strtolower($reference))->first();
        }
        $matches = $query->where(function ($q) use ($reference) {
            $q->whereRaw('1 = 0');
            if (ctype_digit($reference) && strlen($reference) < 19) {
                $q->orWhere('order_number', (int) $reference);
            }
            if (preg_match('/^[A-F0-9]{8}$/D', $reference)) {
                $q->orWhere(fn ($legacy) => $legacy->whereNull('order_number')->whereRaw('lower(substr(cast(id as char(36)), 1, 8)) = ?', [strtolower($reference)]));
            }
        })->limit(2)->get();
        return $matches->count() === 1 && self::display($matches->first()) === '#'.$reference ? $matches->first() : null;
    }
}
