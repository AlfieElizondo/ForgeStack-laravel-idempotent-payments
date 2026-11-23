<?php

declare(strict_types=1);

namespace ForgeStack\IdempotentPayments\Events;

use ForgeStack\IdempotentPayments\Models\PaymentIntent;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The gateway reported a successful payment whose amount or currency does not match
 * the intent. The intent is NOT captured; the app decides what to do (refund, review).
 */
final class PaymentAmountMismatch
{
    use Dispatchable;

    public function __construct(
        public readonly PaymentIntent $paymentIntent,
        public readonly string $paidAmount,
        public readonly ?string $paidCurrency,
    ) {}
}
