<?php

namespace Sellissimo\Core\Contracts;

interface Carrier
{
    /** Use the booking UUID as the idempotency key. Return reference, tracking and an HTTPS label_url. Optionally include a public HTTPS tracking_url. */
    public function book(string $booking, array $shipment): array;

    /** Repeated cancellation of the same booking must be safe. */
    public function void(string $booking, string $reference): void;
}
