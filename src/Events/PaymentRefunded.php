<?php

declare(strict_types=1);

namespace ForgeStack\IdempotentPayments\Events;

use ForgeStack\IdempotentPayments\Models\PaymentIntent;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A captured payment was refunded or charged back at the gateway.
 * Dispatched once, when the intent moves from Captured to Refunded.
 */
final class PaymentRefunded
{
    use Dispatchable;

    public function __construct(
        public readonly PaymentIntent $paymentIntent,
    ) {}
}
