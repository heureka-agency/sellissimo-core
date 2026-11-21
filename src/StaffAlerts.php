<?php

namespace Sellissimo\Core;

use Illuminate\Support\Str;

class StaffAlerts
{
    public function __construct(private Store $store, private Catalog $catalog) {}

    public function queue(string $topic, string $record, array $details, ?object $order): void
    {
        if (!in_array($topic, StoreFeatures::ALERTS, true)) {
            return;
        }
        $sites = $this->store->table('site_features')->when($order, fn ($q) => $q->where('site', $order->site))->get();
        foreach ($sites as $site) {
            $recipients = json_decode($site->recipients, true)[$topic] ?? [];
            if (!$recipients || (!$order && !isset($this->catalog->products($site->site, false, [$details['sku']])[$details['sku']]))) {
                continue;
            }
            $body = $order ? ['id' => $order->id, 'number' => OrderNumber::display($order), 'status' => $order->status] : $details;
            foreach ($recipients as $email) {
                $this->store->table('staff_alerts')->insertOrIgnore([
                    'id' => (string) Str::uuid(), 'delivery_key' => hash('sha256', implode('|', [$site->site, $topic, $record, $email])),
                    'site' => $site->site, 'email' => $email, 'topic' => $topic,
                    'details' => json_encode($body, JSON_THROW_ON_ERROR), 'created_at' => now(),
                ]);
            }
        }
    }

    public function send(string $id): void
    {
        $this->store->db()->transaction(function () use ($id) {
            $query = $this->store->table('staff_alerts')->where('id', $id)->whereNull('sent_at');
            if (!$row = $query->lockForUpdate()->first()) {
                return;
            }
            if (!in_array($row->email, StoreFeatures::get($row->site)['recipients'][$row->topic] ?? [], true)) {
                $query->delete();
                return;
            }
            $detail = json_decode($row->details, true);
            if (isset($detail['id'])) {
                $detail['status'] = $this->store->table('orders')->where('id', $detail['id'])->value('status') ?? $detail['status'];
            }
            $type = str_replace('.', '_', $row->topic);
            $url = isset($detail['id']) ? cp_route('sellissimo.order', $detail['id'])
                : cp_route('sellissimo.inventory', ['site' => $row->site, 'q' => $detail['sku']]);
            app(StoreMail::class)->send($type, $row->site, $row->email, $url, ['detail' => $detail]);
            $query->update(['sent_at' => now()]);
        });
    }
}
