<?php

namespace Sellissimo\Core;

use Sellissimo\Core\Contracts\PaymentRejected;
use Sellissimo\Core\Contracts\PaymentUnavailable;

class PaymentSessions
{
    public function __construct(private Store $store, private Registry $providers) {}

    /** Return null when another request already saved the session or settled the payment. */
    public function start(string $table, object $payment): ?array
    {
        $send = $this->store->db()->transaction(function () use ($table, $payment) {
            $query = $this->store->table($table)->where('id', $payment->id);
            $row = (clone $query)->lockForUpdate()->firstOrFail();
            $pending = $table === 'orders' ? 'pending' : 'awaiting_payment';
            if ($row->status !== $pending || $row->session_id) {
                return false;
            }

            // Commit before contacting the provider. A crash, retry or overlapping request
            // prevents a later refusal from releasing an earlier, possibly accepted payment.
            $binding = app(PaymentSettings::class)->bind($row, $payment->site);
            foreach ($binding as $key => $value) {
                $payment->$key = $value;
            }
            $query->update([...$binding, 'payment_start_state' => $row->payment_start_state === 'ready' ? 'first' : 'uncertain']);
            return true;
        }, 3);
        if (!$send) {
            return null;
        }

        try {
            return $this->providers->gateway($payment->gateway)->start($payment);
        } catch (PaymentRejected $e) {
            report(new PaymentRejected("Payment {$payment->id}: ".$e->getMessage()));
            return ['status' => 'expired', 'request_rejected' => true];
        } catch (PaymentUnavailable $e) {
            throw new PaymentUnavailable("Payment {$payment->id}: ".$e->getMessage());
        }
    }

    public static function canReleaseRejected(object $row): bool
    {
        return $row->payment_start_state === 'first' && !$row->session_id;
    }
}
