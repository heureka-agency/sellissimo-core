<?php

namespace Sellissimo\Core;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Sellissimo\Core\Contracts\DeterminesPaymentAvailability;
use Sellissimo\Core\Contracts\PaymentGateway;

class SimulatedPayments implements PaymentGateway, DeterminesPaymentAvailability
{
    public function __construct(private Store $store) {}

    public static function local(): bool
    {
        return app()->environment(['local', 'testing']);
    }

    public static function enabled(?string $site = null): bool
    {
        return self::local() && in_array('simulator', app(PaymentSettings::class)->preferences($site ?? \Statamic\Facades\Site::current()->handle())['methods'], true);
    }

    public function isAvailable(array $quote, string $site): bool
    {
        return self::enabled($site);
    }

    public function start(object $order): array
    {
        abort_unless(self::local(), 404);
        $sessions = $this->store->table('simulated_payments');
        $sessions->insertOrIgnore(['id' => $order->id, 'status' => 'pending', 'amount' => $order->total, 'currency' => $order->currency,
            'expires_at' => now()->addDay()]);
        $row = $sessions->where('id', $order->id)->firstOrFail();

        return ['id' => 'sim_'.$order->id, 'url' => URL::temporarySignedRoute('sellissimo.simulator', Carbon::parse($row->expires_at), ['payment' => $order->id])];
    }

    public function outcome(object $order): array
    {
        abort_unless(self::local(), 404);
        $query = $this->store->table('simulated_payments')->where('id', $order->id);
        $query->clone()->where('status', 'pending')->where('expires_at', '<=', now())->update(['status' => 'expired']);
        $row = $query->firstOrFail();

        return match ($row->status) {
            'paid' => ['status' => 'paid', 'amount' => (int) $row->amount, 'currency' => $row->currency, 'reference' => 'sim_'.$row->id],
            'declined', 'expired' => ['status' => 'expired'],
            default => ['status' => 'pending'],
        };
    }

    public function supportsRefunds(): bool
    {
        return self::local();
    }

    public function refund(object $order, object $refund): string
    {
        abort_unless(self::local() && $order->gateway === 'simulator', 404);

        return 'succeeded';
    }
}
