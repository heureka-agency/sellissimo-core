<?php

namespace Sellissimo\Core;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class PurchaseTrends
{
    public function __construct(private Store $store, private Catalog $catalog) {}

    /** @return array{popular: array<string, int>, pairs: array<string, array<string, int>>} */
    public function scores(string $site, int $days = 90): array
    {
        $days = max(1, min($days, 365));
        if ($this->store->db()->transactionLevel() > 0) {
            return $this->calculate($site, $days)[0];
        }
        $scope = $this->cacheScope($site);
        $generation = Cache::get($scope.'.generation', 'initial');
        $key = $scope.'.'.$generation.'.'.$days;
        if (($cached = Cache::get($key)) !== null) {
            return $cached;
        }
        [$scores, $expiresAt] = $this->calculate($site, $days);
        Cache::put($key, $scores, $expiresAt);

        return $scores;
    }

    public function orderChanged(string $orderId): void
    {
        $site = $this->store->table('orders')->where('id', $orderId)->value('site');
        if ($site !== null) {
            Cache::put($this->cacheScope($site).'.generation', (string) Str::uuid(), now()->addMinutes(10));
        }
    }

    private function cacheScope(string $site): string
    {
        $connection = $this->store->db();
        $scope = [$connection->getName(), $connection->getDatabaseName(), $connection->getTablePrefix(), $site];

        return 'sellissimo.purchase-trends.'.hash('sha256', serialize($scope));
    }

    private function calculate(string $site, int $days): array
    {
        $popular = [];
        $pairs = [];
        $legacyCatalog = null;
        $oldestSale = null;
        $period = [now()->subDays($days), now()];

        $this->store->table('orders')->where('site', $site)->where('status', 'paid')
            ->where(fn ($query) => $query->whereBetween('paid_at', $period)
                ->orWhere(fn ($query) => $query->whereNull('paid_at')->whereBetween('created_at', $period)))
            ->select(['id', 'receipt'])->selectRaw('coalesce(paid_at, created_at) as purchased_at')
            ->chunkById(100, function (Collection $orders) use ($site, &$oldestSale, &$legacyCatalog, &$popular, &$pairs) {
                $firstPurchase = $orders->min('purchased_at');
                $oldestSale = $oldestSale === null ? $firstPurchase : min($oldestSale, $firstPurchase);
                $orderIds = $orders->pluck('id');
                $returns = $this->store->table('returns')->whereIn('order_id', $orderIds)
                    ->groupBy('order_id', 'line_key')->select('order_id', 'line_key')->selectRaw('sum(quantity) as quantity')
                    ->get()->groupBy('order_id');
                $refunds = $this->store->table('refunds')->whereIn('order_id', $orderIds)->where('status', 'succeeded')
                    ->whereNull('amendment_id')->groupBy('order_id', 'sku')->select('order_id', 'sku')->selectRaw('sum(amount) as amount')
                    ->get()->groupBy('order_id');
                $exchanges = $this->store->table('exchange_parcels')->whereIn('order_id', $orderIds)->whereNotNull('dispatched_at')
                    ->get(['order_id', 'quantity', 'merchandise'])->groupBy('order_id');
                foreach ($orders as $order) {
                    $returned = $returns->get($order->id, collect())->pluck('quantity', 'line_key');
                    $refunded = $refunds->get($order->id, collect())->pluck('amount', 'sku');
                    $counts = [];
                    $credited = [];
                    foreach (json_decode($order->receipt, true, flags: JSON_THROW_ON_ERROR)['lines'] as $line) {
                        $key = $line['key'] ?? $line['sku'];
                        $freeDownload = ($line['downloadable'] ?? $line['digital'] ?? false) && (int) ($line['price'] ?? $line['subtotal'] ?? $line['total']) === 0;
                        if ($freeDownload || ! empty($line['voucher_product']) || ($line['total'] > 0 && $refunded->get($key, 0) >= $line['total'])) {
                            $credited[$key] = true;

                            continue;
                        }
                        $quantity = $line['qty'] - $returned->get($key, 0);
                        $this->addQuantity($counts, $line, $quantity, $site, $legacyCatalog);
                    }
                    foreach ($exchanges->get($order->id, []) as $exchange) {
                        $item = json_decode($exchange->merchandise, true, flags: JSON_THROW_ON_ERROR);
                        if (! isset($credited[$item['line_key']])) {
                            $this->addQuantity($counts, $item['destination'], (int) $exchange->quantity, $site, $legacyCatalog);
                        }
                    }

                    foreach ($counts as $product => $quantity) {
                        $popular[$product] = ($popular[$product] ?? 0) + $quantity;
                        foreach (array_keys($counts) as $other) {
                            if ($product !== $other) {
                                $pairs[$product][$other] = ($pairs[$product][$other] ?? 0) + 1;
                            }
                        }
                    }
                }
            });

        $expiresAt = now()->addMinutes(5);
        if ($oldestSale !== null) {
            $expiresAt = $expiresAt->min(Carbon::parse($oldestSale)->addDays($days)->addSecond());
        }

        return [['popular' => $this->rank($popular), 'pairs' => array_map($this->rank(...), $pairs)], $expiresAt];
    }

    private function addQuantity(array &$counts, array $line, int $quantity, string $site, ?array &$legacyCatalog): void
    {
        if ($quantity <= 0) {
            return;
        }
        $id = $line['entry_id'] ?? null;
        if (! $id) {
            $legacyCatalog ??= $this->catalog->historicalProducts($site);
            $matches = $legacyCatalog[$line['sku']][$line['title']] ?? [];
            $id = count($matches) === 1 ? $matches[0]['id'] : null;
        }
        if ($id !== null) {
            $counts[$id] = ($counts[$id] ?? 0) + $quantity;
        }
    }

    private function rank(array $scores): array
    {
        ksort($scores, SORT_STRING);
        arsort($scores, SORT_NUMERIC);

        return $scores;
    }
}
