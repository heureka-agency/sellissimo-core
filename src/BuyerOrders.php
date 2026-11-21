<?php

namespace Sellissimo\Core;

use Illuminate\Pagination\LengthAwarePaginator;

class BuyerOrders
{
    public function __construct(private Store $store, private Refunds $refunds) {}

    public function page(string $customer, ?string $site = null, int $perPage = 25, string $pageName = 'page'): LengthAwarePaginator
    {
        $orders = $this->store->table('orders')->where('customer', $customer)
            ->when($site !== null, fn ($query) => $query->where('site', $site))
            ->latest('created_at')->orderBy('id')->paginate($perPage, ['*'], $pageName)->withQueryString();
        $orders->through(function ($order) {
            $downloads = [];
            $receipt = json_decode($order->receipt, true, flags: JSON_THROW_ON_ERROR);
            foreach ($receipt['lines'] as $line) {
                if ($this->refunds->downloadable($order, $line)) {
                    $downloads[] = ['title' => trim($line['title'].' '.implode(' / ', array_column($line['options'] ?? [], 'value'))), 'url' => route('sellissimo.download', ['order' => $order->id, 'sku' => $line['key'] ?? $line['sku']])];
                }
            }

            return (object) ['payment_summary' => $this->refunds->buyerSummary($order), 'id' => $order->id, 'receipt_url' => route('sellissimo.receipt', $order->id), 'number' => OrderNumber::display($order), 'status' => $order->status, 'total' => (int) ($receipt['total'] ?? $order->total), 'currency' => $order->currency, 'created_at' => $order->created_at, 'downloads' => $downloads];
        });

        return $orders;
    }
}
