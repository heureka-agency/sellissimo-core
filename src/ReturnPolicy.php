<?php

namespace Sellissimo\Core;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ReturnPolicy
{
    public function days(string $site): ?int
    {
        $days = app(Store::class)->table('return_policies')->where('site', $site)->value('days');
        return $days === null ? null : (int) $days;
    }

    /** The window for a split line closes after the last purchased unit is delivered. */
    public function forOrder(object $order, Collection $parcels): array
    {
        $result = [];
        foreach (json_decode($order->receipt, true)['lines'] as $line) {
            if ($line['digital'] || empty($line['return_policy'])) {
                continue;
            }
            $policy = $line['return_policy'];
            $key = $line['key'] ?? $line['sku'];
            $delivered = 0;
            $last = null;
            foreach ($parcels as $parcel) {
                $qty = $parcel->items[$key] ?? 0;
                if ($qty && $parcel->delivered_at) {
                    $delivered += $qty;
                    $last = max($last, $parcel->delivered_at);
                }
            }
            $deadline = $last && $delivered >= $line['qty'] && isset($policy['days'])
                ? Carbon::parse($last)->addDays($policy['days'])->toDateString() : null;
            $state = match (true) {
                $policy['final_sale'] ?? false => 'final_sale',
                ! $delivered => 'awaiting_delivery',
                $deadline === null => 'partial_delivery',
                $deadline < now()->toDateString() => 'window_closed',
                default => 'return_by',
            };
            $result[$key] = ['title' => $line['title'], 'deadline' => $deadline, 'delivered' => $delivered, 'state' => $state,
                'label' => __('sellissimo::shop.'.$state, ['date' => $deadline, 'days' => $policy['days'] ?? 0])];
        }
        return $result;
    }
}
