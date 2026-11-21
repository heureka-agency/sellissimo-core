<?php

namespace Sellissimo\Core\Commands;

use Illuminate\Console\Command;
use Sellissimo\Core\Checkout;
use Sellissimo\Core\Receipts;
use Sellissimo\Core\Refunds;
use Sellissimo\Core\ShipmentEmails;
use Sellissimo\Core\Store;

class Reconcile extends Command
{
    protected $signature = 'sellissimo:reconcile';

    protected $description = 'Recover pending payments, receipts and refunds';

    public function handle(Store $store, Checkout $checkout, Refunds $refunds, Receipts $receipts): int
    {
        if (! $store->hasTable('orders')) {
            return self::SUCCESS;
        }
        $errors = 0;
        $jobs = [
            [$store->table('vouchers')->whereNull('sent_at')->whereNotNull('delivery_code'), app(\Sellissimo\Core\GiftVouchers::class)->send(...)],
            [$store->table('orders')->where('contact_notice_pending', true), app(\Sellissimo\Core\OrderContact::class)->sendNotice(...)],
            [$store->table('drafts')->where('reserve_until', '<=', now()), app(\Sellissimo\Core\StockHolds::class)->expireDraft(...)],
            [$store->table('return_notices')->whereNull('sent_at'), app(\Sellissimo\Core\ReturnNotices::class)->send(...)],
            [$store->table('orders')->where('acceptance_notice_pending', true), $receipts->sendAcceptance(...)],
            [$store->table('orders')->where('cancel_notice_pending', true), app(\Sellissimo\Core\Cancellations::class)->sendNotice(...)],
            [$store->table('amendments')->where('status','awaiting_payment'), app(\Sellissimo\Core\Amendments::class)->reconcile(...)],
            [$store->table('orders')->where('status', 'pending')->where('gateway', '!=', 'historical'), $checkout->reconcile(...)],
            [$store->table('orders')->where('status', 'paid')->whereNull('emailed_at'), $receipts->send(...)],
            [$store->table('parcels')->whereNotNull('queued_at')->whereNull('emailed_at'), app(ShipmentEmails::class)->send(...)],
            [$store->table('refunds')->where('notice_pending', true), $refunds->sendNotice(...)],
            [$store->table('refunds')->where('status', 'pending'), $refunds->process(...)],
        ];
        $jobs[] = [$store->table('staff_alerts')->whereNull('sent_at'), app(\Sellissimo\Core\StaffAlerts::class)->send(...)];
        $stockAlerts = app(\Sellissimo\Core\StockSubscriptions::class);
        $jobs[] = [$stockAlerts->due(), $stockAlerts->send(...)];
        $store->table('stock_subscriptions')->whereNull('confirmed_at')->where('requested_at', '<=', now()->subDays(2))->delete();
        $recovery = app(\Sellissimo\Core\CartRecovery::class);
        if (\Sellissimo\Core\OperationalSettings::shared('recovery_automatic', 'recovery.automatic')) {
            $jobs[] = [$recovery->inactive()->whereNotNull('recovery_email')->whereNull('reminded_at'), $recovery->remind(...)];
        }
        $webhooks = app(\Sellissimo\Core\Webhooks::class);
        $jobs[] = [$webhooks->due(), $webhooks->deliver(...)];
        $store->table('claim_invitations')->where('expires_at', '<=', now())->delete();
        $recovery->prune();
        app(\Sellissimo\Core\CommerceFields::class)->pruneUploads();
        foreach ($jobs as [$query, $run]) {
            foreach ($query->lazyById(100) as $row) {
                try {
                    $run($row->id);
                } catch (\Throwable $e) {
                    report($e);
                    $this->error("Could not reconcile {$row->id}. See application log.");
                    $errors++;
                }
            }
        }

        return $errors ? self::FAILURE : self::SUCCESS;
    }
}
