<?php

namespace Sellissimo\Core;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class ReturnNotices
{
    public function __construct(private Store $store) {}

    public function history(array $ids)
    {
        return $this->store->table('return_notices')->whereIn('return_id', $ids)->orderBy('sequence')
            ->get(['return_id', 'action', 'replacement', 'message', 'tracking', 'created_at'])
            ->each(fn ($event) => $event->label = __('sellissimo::mail.return_actions.'.$event->action))->groupBy('return_id');
    }

    public function queue(string $return, array $input, ?string $replacement): void
    {
        $id = $input['operation_id'] ?? (string) Str::uuid();
        $this->store->table('return_notices')->insert(['id' => $id, 'return_id' => $return, 'action' => $input['action'],
            'shipping_digest' => $input['shipping_digest'] ?? null, 'replacement' => $replacement, 'message' => $input['message'] ?? null, 'tracking' => $input['tracking'] ?? null, 'created_at' => now()]);
        $this->store->db()->afterCommit(function () use ($id) {
            try {
                $this->send($id);
            } catch (\Throwable $error) {
                report($error);
            }
        });
    }

    public function send(string $id): void
    {
        $target = $this->store->table('return_notices')->where('id', $id)->firstOrFail();
        // Send earlier decisions first, even when a new message arrives during a mail outage.
        $pending = $this->store->table('return_notices')->where('return_id', $target->return_id)->whereNull('sent_at')
            ->where('sequence', '<=', $target->sequence)->orderBy('sequence')->pluck('id');
        foreach ($pending as $noticeId) {
            $this->store->db()->transaction(function () use ($target, $noticeId) {
                $request = $this->store->table('return_requests')->where('id', $target->return_id)->lockForUpdate()->firstOrFail();
                $query = $this->store->table('return_notices')->where('id', $noticeId);
                $notice = $query->lockForUpdate()->firstOrFail();
                if ($notice->sent_at) {
                    return;
                }
                $order = $this->store->table('orders')->where('id', $request->order_id)->firstOrFail();
                $url = \Sellissimo\Core\OrderLinks::signed($order, 'sellissimo.buyer.returns', now()->addDays(\Sellissimo\Core\OperationalSettings::accessDays($order)), ['order' => $order->id]);
                app(StoreMail::class)->send('return_update', $order->site, $order->email, $url, ['order' => $order, 'notice' => $notice]);
                $query->update(['sent_at' => now()]);
            });
        }
    }
}
