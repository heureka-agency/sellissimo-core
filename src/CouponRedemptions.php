<?php

namespace Sellissimo\Core;

use Illuminate\Database\Query\Builder;
use Statamic\Facades\User;

class CouponRedemptions
{
    public function __construct(private Store $store) {}

    public static function emailHash(string $email): string
    {
        return hash('sha256', mb_strtolower(trim($email)));
    }

    public static function accountEmailHash(?string $customer): ?string
    {
        if ($customer === null) {
            return null;
        }
        $user = request()->user();
        $email = $user && (string) $user->getAuthIdentifier() === $customer
            ? $user->email
            : User::find($customer)?->email();

        return filled($email) ? self::emailHash($email) : null;
    }

    public function lock(string $code): void
    {
        // Both configuration-defined and merchant-created coupons have a serialization point.
        $lock = $this->store->table('coupon_locks')->where('code', $code);
        $lock->insertOrIgnore(['code' => $code]);
        $lock->lockForUpdate()->firstOrFail();
    }

    public function check(string $code, ?int $totalLimit, ?int $customerLimit, ?string $email, ?string $customer, bool $placingOrder, ?string $existingOrder = null): void
    {
        if ($totalLimit === null && $customerLimit === null) {
            return;
        }
        $orders = $this->store->table('orders')->where('coupon_code', $code)->where('status', '!=', 'expired')->when($existingOrder, fn ($q) => $q->where('id', '!=', $existingOrder));
        if ($placingOrder) {
            // A current read also observes the previous lock holder's commit on MySQL's repeatable-read isolation.
            $orders->lockForUpdate();
        }
        abort_if($totalLimit !== null && $this->atLimit(clone $orders, $totalLimit), 422, 'This coupon has reached its redemption limit.');
        if ($customerLimit === null || (! $email && ! $customer)) {
            return;
        }
        $hashes = array_values(array_unique(array_filter([$email ? self::emailHash($email) : null, self::accountEmailHash($customer)])));
        $orders->where(function (Builder $buyer) use ($hashes, $customer) {
            if ($hashes) {
                $buyer->whereIn('coupon_email_hash', $hashes)->orWhereIn('coupon_account_email_hash', $hashes);
            }
            if ($customer !== null) {
                $buyer->orWhere('customer', $customer);
            }
        });
        abort_if($this->atLimit($orders, $customerLimit), 422, 'This coupon has reached its limit for this customer.');
    }

    private function atLimit(Builder $orders, int $limit): bool
    {
        return $limit < 1 || $orders->offset($limit - 1)->first(['id']) !== null;
    }
}
