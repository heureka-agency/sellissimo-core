<?php

namespace Sellissimo\Core\Contracts;

use Illuminate\Support\Facades\Log;
use RuntimeException;

/** The provider cannot complete this operation. Reconcile the same payment unless its first request was explicitly refused. */
class PaymentUnavailable extends RuntimeException
{
    public function report(): bool
    {
        // Gateway stacks can contain credentials and customer details.
        Log::warning($this->getMessage());
        return true;
    }
}
