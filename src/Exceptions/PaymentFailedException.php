<?php

declare(strict_types=1);

namespace ForgeStack\IdempotentPayments\Exceptions;

use RuntimeException;

final class PaymentFailedException extends RuntimeException
{
    public static function becauseGatewayRejected(string $reason): self
    {
        return new self("Gateway rejected the payment: {$reason}");
    }

    public static function becauseIntentAlreadyProcessed(string $reference): self
    {
        return new self("Intent {$reference} was already processed.");
    }
}
