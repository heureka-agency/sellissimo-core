<?php

namespace Sellissimo\Core;

use Illuminate\Validation\ValidationException;

class OrderContact
{
    public function __construct(private Store $store) {}

    public function correct(string $id, array $input, $user): void
    {
        $this->store->db()->transaction(function () use ($id, $input, $user) {
            $orders = $this->store->table('orders')->where('id', $id);
            $order = $orders->lockForUpdate()->firstOrFail();
            abort_unless($order->customer === null, 409, __('Only guest orders can have their receipt email corrected.'));
            abort_unless((int) $order->contact_revision === (int) $input['revision'], 409, __('This contact changed. Reload the order.'));
            $email = trim($input['email']);
            if (strcasecmp($order->email, $email) === 0) {
                throw ValidationException::withMessages(['email' => __('Enter the corrected email address.')]);
            }
            $orders->update(['email' => $email, 'contact_revision' => $order->contact_revision + 1, 'contact_notice_pending' => true]);
            $this->store->table('drafts')->where('cart_id', $order->cart_id)->increment('revision');
            $this->store->recordActivity($id, 'contact', __('Guest receipt email changed from :before to :after. Buyer verification: :reason', [
                'before' => $order->email, 'after' => $email, 'reason' => $input['reason'],
            ]), $user->name() ?: $user->email(), (string) $user->id());
        }, 3);

        try {
            $this->sendNotice($id);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function sendNotice(string $id): void
    {
        $this->store->db()->transaction(function () use ($id) {
            $orders = $this->store->table('orders')->where('id', $id);
            $order = $orders->lockForUpdate()->firstOrFail();
            if ($order->contact_notice_pending) {
                app(StoreMail::class)->send('receipt_link', $order->site, $order->email, Checkout::link($order), ['order' => $order]);
                $orders->update(['contact_notice_pending' => false]);
            }
        });
    }
}
