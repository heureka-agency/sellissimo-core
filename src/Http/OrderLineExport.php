<?php

namespace Sellissimo\Core\Http;

use Brick\Math\BigInteger;
use Sellissimo\Core\Money;
use Sellissimo\Core\OrderNumber;
use Sellissimo\Core\OrderQueueFields;
use Sellissimo\Core\Store;

final class OrderLineExport
{
    public function __construct(private Store $store) {}

    public function download($orders, array $columns)
    {
        return response()->streamDownload(function () use ($orders, $columns) {
            $stream = fopen('php://output', 'wb');
            $write = fn ($row) => fputcsv($stream, array_map([OrderDocumentsController::class, 'spreadsheetText'], $row), ',', '"', '', "\r\n");
            $write(['Order ID', 'Order number', 'Site', 'Currency', 'Type', 'SKU', 'Line key', 'Description', 'Quantity', 'Discount', 'Tax', 'Total', 'Settled refund', ...array_values($columns)]);
            $fields = app(OrderQueueFields::class);
            $orders->chunkById(200, function ($batch) use ($write, $columns, $fields) {
                $refunds = $this->store->table('refunds')->whereIn('order_id', $batch->pluck('id'))->where('status', 'succeeded')
                    ->orderBy('settled_at')->orderBy('id')->get()->groupBy('order_id');
                foreach ($batch as $order) {
                    $receipt = json_decode($order->receipt, true, flags: JSON_THROW_ON_ERROR);
                    $values = $fields->values($order, $receipt);
                    $custom = array_map(fn ($key) => $values[substr($key, 6)] ?? '', array_keys($columns));
                    foreach ($this->rows($receipt, $refunds->get($order->id, collect())) as $key => $line) {
                        $amount = fn ($field) => Money::decimal($line[$field] ?? 0, $order->currency);
                        $write([$order->id, OrderNumber::display($order), $order->site, $order->currency, $line['type'], $line['sku'] ?? '',
                            $key, $line['title'], $line['qty'] ?? '', $amount('discount'), $amount('tax'), $amount('total'), $amount('refunded'), ...$custom]);
                    }
                }
            });
            fclose($stream);
        }, 'sellissimo-order-lines-'.now()->toDateString().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function rows(array $receipt, $refunds): array
    {
        $lines = [];
        foreach ($receipt['lines'] as $line) {
            $lines[$line['key'] ?? $line['sku']] = $line + ['type' => 'item', 'refunded' => 0];
        }
        if (isset($receipt['shipping']['amount'])) {
            $shipping = $receipt['shipping'];
            $lines['@shipping'] = ['type' => 'shipping', 'title' => $shipping['label'] ?? 'Shipping', 'total' => $shipping['amount'], 'tax' => $shipping['tax'] ?? 0, 'refunded' => 0];
        }
        foreach ($receipt['charges'] ?? [] as $handle => $charge) {
            $lines['@charge:'.$handle] = $charge + ['type' => 'charge', 'title' => $charge['label'], 'refunded' => 0];
        }

        // Assign targeted refunds first, then share whole-order refunds proportionally across the remaining balance.
        foreach ($refunds->sortBy(fn ($refund) => in_array($refund->sku, ['@all', '@provider'], true) ? 1 : 0) as $refund) {
            $remaining = (int) $refund->amount;
            $keys = in_array($refund->sku, ['@all', '@provider'], true) ? array_keys($lines) : [$refund->sku];
            $balance = 0;
            foreach ($keys as $key) {
                $balance += max(0, ($lines[$key]['total'] ?? 0) - ($lines[$key]['refunded'] ?? 0));
            }
            foreach ($keys as $key) {
                $available = max(0, ($lines[$key]['total'] ?? 0) - ($lines[$key]['refunded'] ?? 0));
                if ($available && $remaining) {
                    $share = min($available, BigInteger::of($remaining)->multipliedBy($available)->quotient($balance)->toInt());
                    $lines[$key]['refunded'] += $share;
                    $remaining -= $share;
                    $balance -= $available;
                }
            }
            if ($remaining) {
                $key = 'refund:'.$refund->id;
                $lines[$key] = ['type' => 'adjustment', 'title' => $refund->sku, 'total' => 0, 'refunded' => $remaining];
            }
        }

        return $lines;
    }
}
