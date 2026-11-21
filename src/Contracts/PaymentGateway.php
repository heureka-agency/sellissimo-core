<?php

namespace Sellissimo\Core\Contracts;

interface PaymentGateway
{
    /**
     * Repeated calls for the same order must create at most one payable session. Return id and url.
     * Throw PaymentRejected only when the provider confirms this creation request was refused.
     * Throw PaymentUnavailable when the result is unknown. Keep diagnostics free of secrets.
     */
    public function start(object $order): array;

    /** Return pending, paid or expired; paid includes verified amount, currency and payment reference. */
    public function outcome(object $order): array;

    public function supportsRefunds(): bool;

    /** Return pending, failed or succeeded. Retrying the same refund ID must never send money twice. */
    public function refund(object $order, object $refund): string;
}
