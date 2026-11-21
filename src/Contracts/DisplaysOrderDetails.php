<?php

namespace Sellissimo\Core\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

interface DisplaysOrderDetails
{
    /** Staff-facing rows with a label, value and optional HTTPS link, localized by the provider. */
    public function orderDetails(object $order, array $receipt, Authenticatable $staff): array;
}
