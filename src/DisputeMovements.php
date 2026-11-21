<?php

namespace Sellissimo\Core;

use Carbon\CarbonImmutable;

class DisputeMovements
{
    public function __construct(private Store $store) {}

    /** The caller holds the order lock. A provider replay can supply a previously missing settlement date. */
    public function record(string $reference, string $dispute, string $order, string $currency, int $amount, int $fee, ?CarbonImmutable $at): void
    {
        $rows = $this->store->table('dispute_movements')->where('reference', $reference);
        $values = ['dispute_id' => $dispute, 'order_id' => $order, 'currency' => $currency, 'amount' => $amount, 'fee' => $fee];
        $date = $at?->setTimezone(config('app.timezone'))->toDateTimeString();
        if ($previous = $rows->first()) {
            foreach ($values as $key => $value) {
                throw_unless((string) $previous->$key === (string) $value, 'Dispute movement does not match its provider reference.');
            }
            if ($date && !$previous->occurred_at) {
                $rows->update(['occurred_at' => $date]);
            }
            return;
        }
        $this->store->table('dispute_movements')->insert(['reference' => $reference, ...$values, 'occurred_at' => $date]);
    }
}
