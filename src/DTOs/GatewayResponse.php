<?php

declare(strict_types=1);

namespace ForgeStack\IdempotentPayments\DTOs;

use ForgeStack\IdempotentPayments\Enums\PaymentStatus;

final readonly class GatewayResponse
{
    public function __construct(
        public PaymentStatus $status,
        public string        $reference,
        public ?string       $checkoutUrl  = null,
        public ?string       $errorMessage = null,
        public array         $rawPayload   = [],
    ) {}

    public function isSuccessful(): bool
    {
        return $this->status === PaymentStatus::Captured;
    }

    public function isPending(): bool
    {
        return $this->status === PaymentStatus::Pending;
    }

    public function isFailed(): bool
    {
        return $this->status === PaymentStatus::Failed;
    }
}
