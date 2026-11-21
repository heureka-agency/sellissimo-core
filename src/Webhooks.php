<?php

namespace Sellissimo\Core;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class Webhooks
{
    public const TOPICS = ['order.placed', 'order.paid', 'order.refunded', 'parcel.shipped', 'order.shipped', 'parcel.delivered', 'order.delivered', 'stock.low', 'stock.exhausted', 'return.received', 'product.downloaded', 'order.cancelled'];

    public function __construct(private Store $store, private WebhookTransport $transport) {}

    /** Enqueue in the transaction that records the order event. */
    public function enqueue(string $topic, ?object $order, string $record, array $details = []): void
    {
        $receipt = $order ? json_decode($order->receipt, true) : null;
        foreach ($this->store->table('webhooks')->where('enabled', true)->get() as $endpoint) {
            if (! in_array($topic, json_decode($endpoint->topics, true), true)) {
                continue;
            }
            $id = (string) Str::uuid();
            $body = ['id' => $id, 'topic' => $topic, 'occurred_at' => now()->toIso8601String(), 'order' => $order ? [
                'id' => $order->id, 'number' => OrderNumber::display($order), 'site' => $order->site, 'currency' => $order->currency,
                'total' => (int) $order->total, 'lines' => collect($receipt['lines'])->map(fn ($line) => ['sku' => $line['sku'], 'quantity' => $line['qty']])->values()->all(),
            ] : null, 'details' => (object) $details];
            $this->store->table('deliveries')->insertOrIgnore(['id' => $id, 'endpoint' => $endpoint->id,
                'event_key' => $topic.'.'.$record, 'topic' => $topic, 'payload' => json_encode($body, JSON_THROW_ON_ERROR),
                'status' => 'pending', 'created_at' => now(), 'available_at' => now()]);
        }
    }

    /** Publish store activity only when the owning transaction commits. */
    public function activity(string $topic, string $record, array $details, ?object $order = null): void
    {
        $this->enqueue($topic, $order, $record, $details);
        app(StaffAlerts::class)->queue($topic, $record, $details, $order);
        $payload = ['order_id' => $order?->id, ...$details];
        $this->store->db()->afterCommit(fn () => event('sellissimo.'.$topic, [$payload]));
    }

    public function due()
    {
        return $this->store->table('deliveries')->whereIn('status', ['pending', 'sending'])->where('available_at', '<=', now())
            ->whereIn('endpoint', $this->store->table('webhooks')->select('id')->where('enabled', true));
    }

    public function deliver(string $id): void
    {
        $work = $this->store->db()->transaction(function () use ($id) {
            $row = $this->due()->where('id', $id)->lockForUpdate()->first();
            if (! $row) {
                return null;
            }
            $endpoint = $this->store->table('webhooks')->where('id', $row->endpoint)->firstOrFail();
            $lease = (string) Str::uuid();
            $this->store->table('deliveries')->where('id', $id)->update(['status' => 'sending', 'lease' => $lease,
                'attempts' => ++$row->attempts, 'available_at' => now()->addMinutes(2)]);

            return [$row, $endpoint, $lease];
        }, 3);
        if (! $work) {
            return;
        }
        [$row, $endpoint, $lease] = $work;
        $code = null;
        $error = null;
        try {
            $time = now()->timestamp;
            $signature = hash_hmac('sha256', $time.'.'.$row->payload, Crypt::decryptString($endpoint->secret));
            $code = $this->transport->post($endpoint->url, $row->payload, ['Content-Type: application/json', 'Sellissimo-Delivery: '.$id,
                'Sellissimo-Topic: '.$row->topic, 'Sellissimo-Timestamp: '.$time, 'Sellissimo-Signature: v1='.$signature]);
            if ($code < 200 || $code >= 300) {
                $error = 'Endpoint returned HTTP '.$code.'.';
            }
        } catch (\Throwable $exception) {
            report($exception);
            $error = 'Connection or signing failed.';
        }
        $this->store->table('deliveries')->where('id', $id)->where('lease', $lease)->update([
            'lease' => null, 'http_status' => $code, 'error' => $error, 'status' => ! $error ? 'delivered' : ($row->attempts >= 6 ? 'failed' : 'pending'),
            'available_at' => now()->addMinutes(min(1440, 2 ** min($row->attempts, 10))),
        ]);
    }
}
