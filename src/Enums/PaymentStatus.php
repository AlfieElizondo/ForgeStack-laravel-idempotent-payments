<?php

declare(strict_types=1);

namespace ForgeStack\IdempotentPayments\Enums;

enum PaymentStatus: string
{
    case Pending  = 'pending';
    case Captured = 'captured';
    case Failed   = 'failed';
    case Refunded = 'refunded';

    public function isSuccessful(): bool
    {
        return $this === self::Captured;
    }
}
