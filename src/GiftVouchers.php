<?php

namespace Sellissimo\Core;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GiftVouchers
{
    public function __construct(private Store $store) {}

    /** The code is a bearer instrument. Only its hash survives successful delivery. */
    public function issue(string $source, string $site, string $currency, int $amount, string $recipient,
        ?string $order = null, ?string $line = null, ?string $actor = null): object
    {
        abort_unless($amount > 0 && $amount <= 99999999 && filter_var($recipient, FILTER_VALIDATE_EMAIL), 422);
        $source = hash('sha256', $source);
        return $this->store->db()->transaction(function () use ($source, $site, $currency, $amount, $recipient, $order, $line, $actor) {
            $code = strtoupper(bin2hex(random_bytes(16)));
            $id = (string) Str::uuid();
            $fresh = $this->store->table('vouchers')->insertOrIgnore([
                'id' => $id, 'source' => $source, 'site' => $site, 'currency' => $currency, 'face' => $amount, 'balance' => $amount,
                'code_hash' => hash('sha256', $code), 'delivery_code' => Crypt::encryptString($code), 'ending' => substr($code, -4),
                'purchase_id' => $order, 'line_key' => $line, 'recipient' => $recipient, 'actor' => $actor, 'created_at' => now(),
            ]);
            $voucher = $this->store->table('vouchers')->where('source', $source)->lockForUpdate()->firstOrFail();
            abort_unless($voucher->site === $site && $voucher->currency === $currency && (int) $voucher->face === $amount
                && $voucher->recipient === $recipient && $voucher->purchase_id === $order, 409);
            if ($fresh) {
                $this->movement($voucher, 'issue:'.$id, 'issue', $amount, $order);
            }
            return $voucher;
        }, 3);
    }

    public function send(string $id): void
    {
        $this->store->db()->transaction(function () use ($id) {
            $query = $this->store->table('vouchers')->where('id', $id);
            $voucher = $query->lockForUpdate()->firstOrFail();
            if (!$voucher->delivery_code || $voucher->sent_at) {
                return;
            }
            $code = implode('-', str_split(Crypt::decryptString($voucher->delivery_code), 4));
            $url = \Illuminate\Support\Facades\URL::route('sellissimo.voucher.balance', ['site' => $voucher->site]);
            app(StoreMail::class)->send('voucher', $voucher->site, $voucher->recipient, $url,
                ['code' => $code, 'amount' => Money::display($voucher->face, $voucher->currency)]);
            $query->update(['sent_at' => now(), 'delivery_code' => null]);
        });
    }

    public function byCode(string $code, string $site, ?string $currency, bool $lock = false): object
    {
        $hash = hash('sha256', strtoupper(str_replace(['-', ' '], '', trim($code))));
        $voucher = $this->store->table('vouchers')->where('code_hash', $hash)->where('site', $site)
            ->when($currency !== null, fn ($query) => $query->where('currency', $currency))->when($lock, fn ($query) => $query->lockForUpdate())->first();
        if (!$voucher || !StoreFeatures::enabled($site, 'vouchers')) {
            throw ValidationException::withMessages(['voucher' => __('This gift voucher is unavailable.')]);
        }
        return $voucher;
    }

    public function quote(array $receipt, string $code, string $site, bool $placing): array
    {
        $voucher = $this->byCode($code, $site, $receipt['currency'], $placing);
        $voucherLines = collect($receipt['lines'])->where('voucher_product', true)->sum('total');
        $eligible = max(0, $receipt['total'] - $voucherLines);
        $amount = min((int) $voucher->balance, $eligible);
        if (!$amount) {
            throw ValidationException::withMessages(['voucher' => __('This voucher has no available balance for these items.')]);
        }
        return ['id' => $voucher->id, 'ending' => $voucher->ending, 'amount' => $amount];
    }

    public function purchase(object $order, array $receipt): void
    {
        foreach ($receipt['lines'] as $line) {
            if (empty($line['voucher_product'])) {
                continue;
            }
            $recipient = collect($line['options'])->firstWhere('handle', 'gift_recipient')['value'] ?? $order->email;
            for ($unit = 0; $unit < $line['qty']; $unit++) {
                $key = $line['key'] ?? $line['sku'];
                $this->issue(json_encode([$order->id, $key, $unit], JSON_THROW_ON_ERROR), $order->site, $order->currency,
                    $line['price'], $recipient, $order->id, $key);
            }
        }
    }

    private function movement(object $voucher, string $operation, string $kind, int $amount, ?string $order): void
    {
        $this->store->table('voucher_movements')->insert(['voucher_id' => $voucher->id, 'operation' => $operation,
            'kind' => $kind, 'amount' => $amount, 'order_id' => $order, 'created_at' => now()]);
    }

    /** Caller holds the order transaction. Locks and unique operations serialize balance updates. */
    public function change(string $id, string $operation, string $kind, int $delta, string $order): void
    {
        $query = $this->store->table('vouchers')->where('id', $id);
        $voucher = $query->lockForUpdate()->firstOrFail();
        if ($this->store->table('voucher_movements')->where('operation', $operation)->exists()) {
            return;
        }
        abort_if($delta + (int) $voucher->balance < 0 || $delta + (int) $voucher->balance > (int) $voucher->face, 409, __('Gift voucher balance changed. Review the order again.'));
        $this->movement($voucher, $operation, $kind, $delta, $order);
        $query->update(['balance' => (int) $voucher->balance + $delta]);
    }

    /** Reserve the unused part of issued cards before refunding their purchase price. */
    public function reservePurchaseRefund(object $order, string $lineKey, string $refundId): void
    {
        $receipt = json_decode($order->receipt, true);
        $lines = collect($receipt['lines'])->where('voucher_product', true);
        if ($lines->isEmpty()) {
            return;
        }
        if ($lineKey === '@all') {
            $lines = $lines->sortBy('key');
        } else {
            $lines = $lines->where('key', $lineKey);
        }
        foreach ($lines as $line) {
            $issued = $this->store->table('vouchers')->where('purchase_id', $order->id)->where('line_key', $line['key'])
                ->orderBy('id')->lockForUpdate()->get();
            $face = (int) $issued->sum('face');
            $balance = (int) $issued->sum('balance');
            $movements = $this->store->table('voucher_movements')->whereIn('voucher_id', $issued->pluck('id'));
            $deducted = -(int) (clone $movements)->whereIn('kind', ['purchase_refund', 'purchase_restored'])->sum('amount');
            $external = -(int) $movements->whereIn('kind', ['external_refund', 'external_restored'])->sum('amount');
            if ($lineKey === '@all') {
                $remove = max(0, $face - $deducted - $external);
            } else {
                $refunded = (int) $this->store->table('refunds')->where('order_id', $order->id)->where('sku', $lineKey)
                    ->whereIn('status', ['pending', 'succeeded'])->sum('amount');
                $target = \Brick\Math\BigInteger::of($refunded)->multipliedBy($face)
                    ->plus(intdiv($line['total'], 2))->quotient(max(1, $line['total']))->toInt();
                $remove = max(0, min($face - $external, $target) - $deducted);
            }
            if ($remove > $balance) {
                $maximum = \Brick\Math\BigInteger::of($balance)->multipliedBy($line['total'])->quotient(max(1, $face))->toInt();
                throw ValidationException::withMessages(['amount' => __('Unused refundable value for :sku is :amount.', [
                    'sku' => $line['sku'], 'amount' => Money::display($maximum, $order->currency),
                ])]);
            }
            foreach ($issued as $card) {
                $take = min($remove, (int) $card->balance);
                if ($take > 0) {
                    $this->change($card->id, 'revoke:'.$refundId.':'.$card->id, 'purchase_refund', -$take, $order->id);
                }
                $remove -= $take;
                if (!$remove) {
                    break;
                }
            }
        }
    }

    /** A gateway refund has already happened. Retire the remaining wallet value and record any shortfall for staff. */
    public function externalPurchaseRefund(object $order, object $refund): void
    {
        $vouchers = $this->store->table('vouchers')->where('purchase_id', $order->id)->orderBy('id')->lockForUpdate()->get();
        if ($vouchers->isEmpty()) {
            return;
        }
        $cashRefunded = (int) $this->store->table('refunds')->where('order_id', $order->id)->where('external', true)
            ->whereNull('capture_id')->where('status', 'succeeded')->sum('amount');
        $original = (int) $order->total - (int) $this->store->table('amendments')->where('order_id', $order->id)
            ->where('status', 'applied')->where('difference', '>', 0)->sum('difference');
        $face = (int) $vouchers->sum('face');
        $eligible = \Brick\Math\BigInteger::of(min($original, $cashRefunded))->multipliedBy($face)
            ->plus(intdiv($original, 2))->quotient(max(1, $original))->toInt();
        $previous = $this->store->table('voucher_movements')->whereIn('voucher_id', $vouchers->pluck('id'))
            ->whereIn('kind', ['external_refund', 'external_restored'])->sum('amount');
        $remaining = max(0, $eligible + (int) $previous);
        foreach ($vouchers as $voucher) {
            $part = min($remaining, (int) $voucher->balance);
            if ($part) {
                $this->change($voucher->id, 'external:'.$refund->id.':'.$voucher->id, 'external_refund', -$part, $order->id);
                $remaining -= $part;
            }
        }
        if ($remaining) {
            $this->store->recordActivity($order->id, 'refund', __('Review :amount of gift voucher value after the external refund.', ['amount' => Money::display($remaining, $order->currency)]));
        }
    }

    public function restoreFailedPurchaseRefund(object $order, string $refundId): void
    {
        $moves = $this->store->table('voucher_movements')->where(fn ($query) => $query->where('operation', 'like', 'revoke:'.$refundId.':%')
            ->orWhere('operation', 'like', 'external:'.$refundId.':%'))->orderBy('voucher_id')->get();
        foreach ($moves as $move) {
            $this->change($move->voucher_id, 'undo:'.$refundId.':'.$move->voucher_id, $move->kind === 'external_refund' ? 'external_restored' : 'purchase_restored', -(int) $move->amount, $order->id);
        }
    }

    public static function cashPayment(object $order): object
    {
        if (($order->original_cash_amount ?? null) === null) {
            return $order;
        }
        $payment = clone $order;
        $payment->total = (int) $order->original_cash_amount;
        return $payment;
    }
}
