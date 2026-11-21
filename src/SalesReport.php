<?php

namespace Sellissimo\Core;

use Brick\Math\BigInteger;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;

class SalesReport
{
    public function __construct(private Store $store) {}

    private function portion(int $amount, int $part, int $whole): int
    {
        return $whole ? BigInteger::of($amount)->multipliedBy($part)->quotient($whole)->toInt() : 0;
    }

    /** Completed payments, including historical card orders that predate payment timestamps. */
    public function paidOrders(string|array|null $site): Builder
    {
        return $this->store->table('orders')
            ->when($site !== null, fn ($query) => $query->whereIn('site', (array) $site))
            ->whereIn('status', ['paid', 'refunded', 'cancelled'])
            ->where(fn ($query) => $query->where('status', '!=', 'cancelled')->orWhereNotNull('paid_at')
                ->orWhereNull('gateway')->orWhere('gateway', '!=', 'pay_on_delivery'));
    }

    public function customerCounts(string $start, string $end, string|array|null $site): array
    {
        $purchases = $this->paidOrders($site)
            ->whereNotNull('email')->whereRaw("trim(email) <> ''")
            ->whereRaw('coalesce(paid_at, created_at) < ?', [$end])
            ->groupByRaw('lower(trim(email))')
            ->selectRaw('min(coalesce(paid_at, created_at)) as first_purchase')
            ->havingRaw('max(coalesce(paid_at, created_at)) >= ?', [$start]);
        $counts = $this->store->db()->query()->fromSub($purchases, 'buyers')
            ->selectRaw('count(*) as buyers, count(case when first_purchase < ? then 1 end) as returning_count', [$start])->first();

        return ['new' => (int) $counts->buyers - (int) $counts->returning_count, 'returning' => (int) $counts->returning_count];
    }

    /** Shift attribution between catalog entries without changing order-level cash, tax, discounts or fees. */
    private function attributeExchange(?array &$products, ?array &$variants, array $item, int $quantity, int $net, int $discount, int $refund = 0): void
    {
        $metrics = ['qty' => $quantity, 'gross' => $net + $discount, 'discount' => $discount, 'refunds' => $refund, 'net' => $net - $refund];
        $empty = array_fill_keys(array_keys($metrics), 0);
        foreach (['source' => -1, 'destination' => 1] as $side => $sign) {
            $identity = $item[$side];
            $sku = $identity['sku'];
            $product = $identity['entry_id'] ?? $sku;
            $products[$product] ??= ['label' => $identity['product_title'] ?? $identity['title']] + $empty;
            $variants[$sku] ??= ['label' => $identity['title'], 'sku' => $sku] + $empty;
            foreach ($metrics as $metric => $amount) {
                $products[$product][$metric] += $sign * $amount;
                $variants[$sku][$metric] += $sign * $amount;
            }
        }
    }

    public function period(string $from, string $to, string|array|null $site): array
    {
        $start = Carbon::parse($from)->startOfDay()->toDateTimeString();
        $end = Carbon::parse($to)->addDay()->startOfDay()->toDateTimeString();
        $within = fn ($date) => $date && $date >= $start && $date < $end;
        $inPeriod = fn ($q, $column) => $q->where($column, '>=', $start)->where($column, '<', $end);
        $refundOrders = $inPeriod($this->store->table('refunds')->where('status', 'succeeded'), 'settled_at')->select('order_id');
        $changedOrders = $inPeriod($this->store->table('amendments')->where('status','applied'), 'applied_at')->select('order_id');
        $exchangedOrders = $inPeriod($this->store->table('exchange_parcels'), 'dispatched_at')->select('order_id');
        $orders = $this->paidOrders($site)
            ->where(fn ($q) => $q->where(fn ($q) => $inPeriod($q, 'paid_at'))
                ->orWhere(fn ($q) => $inPeriod($q->whereNull('paid_at'), 'created_at'))->orWhereIn('id', $refundOrders)->orWhereIn('id',$changedOrders)->orWhereIn('id', $exchangedOrders));
        $legacyCatalog = [];
        $currencies = [];
        $daily = [];
        $recordNet = function ($currency, $date, $amount) use (&$daily, $within) {
            if ($within($date)) {
                $day = substr((string) $date, 0, 10);
                $daily[$currency][$day] = ($daily[$currency][$day] ?? 0) + $amount;
            }
        };
        $products = [];
        $variants = [];
        $empty = ['orders' => 0, 'gross' => 0, 'discount' => 0, 'refunds' => 0, 'returned_merchandise' => 0, 'net' => 0, 'tax' => 0, 'shipping' => 0, 'charges' => 0, 'chargebacks' => 0, 'dispute_reversals' => 0, 'dispute_fees' => 0, 'voucher_issued' => 0, 'voucher_redeemed' => 0, 'voucher_restored' => 0, 'voucher_sales' => 0, 'collected' => 0];
        $orders->chunkById(100, function ($batch) use (&$currencies, &$products, &$variants, &$legacyCatalog, $empty, $within, $recordNet) {
            $refunds = $this->store->table('refunds')->whereIn('order_id', $batch->pluck('id'))->where('status', 'succeeded')->orderBy('settled_at')->orderBy('id')->get()->groupBy('order_id');
            $changes = $this->store->table('amendments')->whereIn('order_id',$batch->pluck('id'))->where('status','applied')
                ->orderBy('applied_at')->orderBy('created_at')->get()->groupBy('order_id');
            $parcels = $this->store->table('exchange_parcels')->whereIn('order_id', $batch->pluck('id'))
                ->whereNotNull('dispatched_at')->orderBy('dispatched_at')->orderBy('id')->get()->groupBy('order_id');
            foreach ($batch as $order) {
                $currency = $order->currency;
                $currencies[$currency] ??= $empty;
                $summary = &$currencies[$currency];
                $receipt = json_decode($order->receipt, true);
                $amendments = $changes->get($order->id, collect());
                $saleDate = $order->paid_at ?? $order->created_at;
                $sale = $within($saleDate);
                $summary['orders'] += (int) $sale;
                $lines = [];
                foreach ($receipt['lines'] as $line) {
                    $key = $line['key'] ?? $line['sku'];
                    $tax = (int) ($line['tax'] ?? 0);
                    $discount = (int) ($line['discount'] ?? 0);
                    $total = (int) $line['total'];
                    $gross = $total + $discount - $tax;
                    if (!empty($line['voucher_product'])) {
                        $lines[$key] = ['value' => $total, 'used' => 0, 'tax' => $tax, 'category' => 'voucher_sales'];
                        if ($sale) {
                            $summary['voucher_sales'] += $total - $tax;
                        }
                        continue;
                    }
                    $recordNet($currency, $saleDate, $total - $tax);
                    $legacyProduct = null;
                    if (empty($line['entry_id'])) {
                        $legacyCatalog[$order->site] ??= app(Catalog::class)->historicalProducts($order->site);
                        $matches = $legacyCatalog[$order->site][$line['sku']][$line['title']] ?? [];
                        $legacyProduct = count($matches) === 1 ? $matches[0] : null;
                    }
                    $lines[$key] = ['sku' => $line['sku'], 'product' => $line['entry_id'] ?? $legacyProduct['id'] ?? $line['sku'], 'title' => $line['product_title'] ?? $legacyProduct['title'] ?? $line['title'],
                        'variant' => $line['title'], 'gross' => $sale ? $gross : 0, 'discount' => $sale ? $discount : 0, 'refunds' => 0,
                        'qty' => $sale ? (int) $line['qty'] : 0, 'value' => $total, 'tax' => $tax, 'used' => 0];
                }
                $shipping = (int) ($receipt['shipping']['amount'] ?? 0);
                $shippingTax = (int) ($receipt['shipping']['tax'] ?? 0);
                $lines['@shipping'] = ['value' => $shipping, 'used' => 0, 'tax' => $shippingTax, 'category' => 'shipping'];
                foreach ($receipt['charges'] ?? [] as $handle => $charge) {
                    $lines['@charge:'.$handle] = ['value' => $charge['total'], 'used' => 0, 'tax' => $charge['tax'], 'category' => 'charges'];
                }
                if ($sale) {
                    $summary['tax'] += (int) ($receipt['tax'] ?? 0);
                    $summary['shipping'] += $shipping - $shippingTax;
                    $summary['charges'] += array_sum(array_column($receipt['charges'] ?? [], 'subtotal'));
                    $summary['collected'] += (int) $order->total - (int) $order->voucher_amount;
                    $summary['voucher_redeemed'] += (int) $order->voucher_amount;
                }
                // The current receipt drives fulfillment and refund allocations. Move each
                // amendment's merchandise and cash from the sale date to its settlement date.
                foreach ($amendments as $change) {
                    $inPeriod = $within($change->applied_at);
                    $factor = (int) $inPeriod - (int) $sale;
                    if ($sale || $inPeriod) {
                        $net = fn ($json) => collect(json_decode($json, true)['lines'])->sum(fn ($line) => $line['total'] - $line['tax']);
                        $difference = $net($change->proposed) - $net($change->previous);
                        $recordNet($currency, $saleDate, -$difference);
                        $recordNet($currency, $change->applied_at, $difference);
                    }
                    if (!$factor) {
                        continue;
                    }
                    $summary['collected'] += $factor * max(0, (int) $change->difference);
                    foreach ([[-$factor, $change->previous], [$factor, $change->proposed]] as [$sign,$snapshot]) {
                        $snapshot = json_decode($snapshot,true);
                        $summary['shipping'] += $sign * (($snapshot['shipping']['amount'] ?? 0) - ($snapshot['shipping']['tax'] ?? 0));
                        $summary['tax'] += $sign * $snapshot['tax'];
                        $summary['charges'] += $sign * array_sum(array_column($snapshot['charges'] ?? [], 'subtotal'));
                        foreach ($snapshot['lines'] as $item) {
                            $product = $item['entry_id'] ?? $item['sku'];
                            $sku = $item['sku'];
                            $gross = $sign * ($item['total'] + $item['discount'] - $item['tax']);
                            $savings = $sign * $item['discount'];
                            $products[$currency][$product] ??= ['label'=>$item['product_title'] ?? $item['title'],'qty'=>0,'gross'=>0,'discount'=>0,'refunds'=>0,'net'=>0];
                            $variants[$currency][$sku] ??= ['label'=>$item['title'],'sku'=>$sku,'qty'=>0,'gross'=>0,'discount'=>0,'refunds'=>0,'net'=>0];
                            foreach (['qty'=>$sign * $item['qty'],'gross'=>$gross,'discount'=>$savings,'net'=>$gross - $savings] as $key=>$value) {
                                $products[$currency][$product][$key] += $value;
                                $variants[$currency][$sku][$key] += $value;
                            }
                            $summary['gross'] += $gross;
                            $summary['discount'] += $savings;
                            $summary['net'] += $gross - $savings;
                        }
                    }
                }
                $exchanges = [];
                foreach ($parcels->get($order->id, []) as $parcel) {
                    $item = json_decode($parcel->merchandise, true);
                    if ($item['source']['sku'] === $item['destination']['sku']) {
                        continue;
                    }
                    if ($within($parcel->dispatched_at)) {
                        $this->attributeExchange($products[$currency], $variants[$currency], $item, $parcel->quantity, $item['net'], $item['discount']);
                    }
                    $exchanges[$item['line_key']][] = ['date' => $parcel->dispatched_at, 'item' => $item, 'unrefunded' => $item['net']];
                }
                // A whole-order refund is the balance after all individually reserved refunds.
                $settled = $refunds->get($order->id, collect())->sortBy(fn ($r) => in_array($r->sku, ['@all', '@provider'], true) ? 1 : 0);
                foreach ($settled as $refund) {
                    $amount = (int) $refund->amount;
                    $count = $within($refund->settled_at);
                    if ($count) {
                        $summary['refunds'] += $amount;
                        if ($refund->voucher_id) {
                            $summary['voucher_restored'] += $amount;
                        } else {
                            $summary['collected'] -= $amount;
                        }
                    }
                    $keys = in_array($refund->sku, ['@all', '@provider'], true) ? array_keys($lines) : [$refund->sku];
                    $balance = 0;
                    foreach ($keys as $key) {
                        if (isset($lines[$key])) {
                            $balance += $lines[$key]['value'] - $lines[$key]['used'];
                        }
                    }
                    $remaining = $amount;
                    foreach ($keys as $key) {
                        if (! isset($lines[$key])) {
                            continue;
                        }
                        $line = &$lines[$key];
                        $available = $line['value'] - $line['used'];
                        $part = min($available, $this->portion($remaining, $available, $balance));
                        $remaining -= $part;
                        $balance -= $available;
                        $totalTax = $this->portion($line['used'] + $part, $line['tax'], $line['value']) - $this->portion($line['used'], $line['tax'], $line['value']);
                        if (isset($exchanges[$key]) && $part > 0) {
                            $netAvailable = $available - $line['tax'] + $this->portion($line['used'], $line['tax'], $line['value']);
                            $netRefund = $part - $totalTax;
                            foreach ($exchanges[$key] as &$exchange) {
                                if (!$refund->settled_at || $exchange['date'] > $refund->settled_at || $netAvailable <= 0) {
                                    continue;
                                }
                                $value = min($netAvailable, $exchange['unrefunded']);
                                $share = $this->portion($netRefund, $value, $netAvailable);
                                if ($count && $share) {
                                    $this->attributeExchange($products[$currency], $variants[$currency], $exchange['item'], 0, 0, 0, $share);
                                }
                                $exchange['unrefunded'] -= $share;
                                $netRefund -= $share;
                                $netAvailable -= $value;
                            }
                            unset($exchange);
                        }
                        $line['used'] += $part;
                        if ($count && isset($line['category'])) {
                            $summary[$line['category']] -= $part - $totalTax;
                            $summary['tax'] -= $totalTax;
                        } elseif ($count) {
                            $line['refunds'] += $part - $totalTax;
                            $recordNet($currency, $refund->settled_at, -($part - $totalTax));
                            $summary['tax'] -= $totalTax;
                        }
                        unset($line);
                    }
                }
                foreach ($lines as $line) {
                    if (isset($line['category']) || (! $sale && ! $line['refunds'])) {
                        continue;
                    }
                    $summary['gross'] += $line['gross'];
                    $summary['discount'] += $line['discount'];
                    $summary['returned_merchandise'] += $line['refunds'];
                    $product = $line['product'];
                    $sku = $line['sku'];
                    $products[$currency][$product] ??= ['label' => $line['title'], 'qty' => 0, 'gross' => 0, 'discount' => 0, 'refunds' => 0, 'net' => 0];
                    $variants[$currency][$sku] ??= ['label' => $line['variant'], 'sku' => $sku, 'qty' => 0, 'gross' => 0, 'discount' => 0, 'refunds' => 0, 'net' => 0];
                    foreach (['qty', 'gross', 'discount', 'refunds'] as $metric) {
                        $products[$currency][$product][$metric] += $line[$metric];
                        $variants[$currency][$sku][$metric] += $line[$metric];
                    }
                    $net = $line['gross'] - $line['discount'] - $line['refunds'];
                    $products[$currency][$product]['net'] += $net;
                    $variants[$currency][$sku]['net'] += $net;
                    $summary['net'] += $net;
                }
                unset($summary);
            }
        });
        $issued = $this->store->table('vouchers')->where('created_at', '>=', $start)->where('created_at', '<', $end)
            ->when($site !== null, fn ($query) => $query->whereIn('site', (array) $site))->groupBy('currency')->selectRaw('currency, sum(face) as issued')->get();
        foreach ($issued as $row) {
            $currencies[$row->currency] ??= $empty;
            $currencies[$row->currency]['voucher_issued'] = (int) $row->issued;
        }
        $movements = $this->store->table('dispute_movements')->join('sellissimo_orders as o', 'o.id', '=', 'sellissimo_dispute_movements.order_id')
            ->when($site !== null, fn ($q) => $q->whereIn('o.site', (array) $site));
        $byCurrency = (clone $movements)->where('occurred_at', '>=', $start)->where('occurred_at', '<', $end)
            ->groupBy('sellissimo_dispute_movements.currency')->select('sellissimo_dispute_movements.currency')
            ->selectRaw('sum(case when amount < 0 then -amount else 0 end) as debits, sum(case when amount > 0 then amount else 0 end) as credits, sum(fee) as fees')->get();
        foreach ($byCurrency as $row) {
            $currencies[$row->currency] ??= $empty;
            $currencies[$row->currency]['chargebacks'] = (int) $row->debits;
            $currencies[$row->currency]['dispute_reversals'] = (int) $row->credits;
            $currencies[$row->currency]['dispute_fees'] = (int) $row->fees;
            $currencies[$row->currency]['collected'] += $row->credits - $row->debits - $row->fees;
        }
        $unreconciledDisputes = $this->store->table('disputes')->join('sellissimo_orders as o', 'o.id', '=', 'sellissimo_disputes.order_id')
            ->whereNull('cash_checked_at')->when($site !== null, fn ($q) => $q->whereIn('o.site', (array) $site))->count();
        $undatedDisputes = $movements->whereNull('occurred_at')->count();
        $unknown = $this->store->table('refunds')->join('sellissimo_orders as o', 'o.id', '=', 'sellissimo_refunds.order_id')
            ->where('sellissimo_refunds.status', 'succeeded')->whereNull('settled_at')->when($site !== null, fn ($q) => $q->whereIn('o.site', (array) $site))
            ->select('o.currency')->selectRaw('sum(amount) as amount')->groupBy('o.currency')->pluck('amount', 'currency');

        return ['unreconciledDisputes' => $unreconciledDisputes, 'undatedDisputes' => $undatedDisputes, 'customers' => $this->customerCounts($start, $end, $site), 'daily' => $daily, 'currencies' => $currencies, 'products' => $products, 'variants' => $variants, 'undated' => $unknown];
    }
}
