<?php

declare(strict_types=1);

namespace ForgeStack\IdempotentPayments\Events;

use ForgeStack\IdempotentPayments\Models\PaymentIntent;
use Illuminate\Foundation\Events\Dispatchable;

final class PaymentFailed
{
    use Dispatchable;

    public function __construct(
        public readonly PaymentIntent $paymentIntent,
        public readonly string        $reason,
    ) {}
}
