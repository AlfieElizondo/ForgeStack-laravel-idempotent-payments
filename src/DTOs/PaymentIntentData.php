<?php

declare(strict_types=1);

namespace ForgeStack\IdempotentPayments\DTOs;

final readonly class PaymentIntentData
{
    public function __construct(
        public string $publicId,
        public float  $amount,
        public string $currency,
        public string $description,
        public string $customerEmail,
        public string $customerName,
    ) {}

    public function toArray(): array
    {
        return [
            'public_id'   => $this->publicId,
            'amount'      => $this->amount,
            'currency'    => $this->currency,
            'description' => $this->description,
            'customer'    => [
                'email' => $this->customerEmail,
                'name'  => $this->customerName,
            ],
        ];
    }
}
