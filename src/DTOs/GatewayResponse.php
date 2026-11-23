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
        // P-11: public_id del intent cuando la pasarela lo devuelve (external_reference en Mercado Pago).
        // Tiene prioridad sobre $reference para encontrar el intent: el id del pago no es el de la preferencia.
        public ?string       $intentPublicId = null,
        // S-09: monto y moneda que la pasarela dice que se cobraron (null si no los reporta).
        public ?string       $paidAmount = null,
        public ?string       $paidCurrency = null,
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

    public function isRefunded(): bool
    {
        return $this->status === PaymentStatus::Refunded;
    }
}
