<?php

declare(strict_types=1);

namespace ForgeStack\IdempotentPayments\Drivers\Gateways;

use ForgeStack\IdempotentPayments\Contracts\PaymentGatewayInterface;
use ForgeStack\IdempotentPayments\DTOs\GatewayResponse;
use ForgeStack\IdempotentPayments\DTOs\PaymentIntentData;
use ForgeStack\IdempotentPayments\Enums\PaymentStatus;
use Illuminate\Http\Request;

/**
 * Zero-amount / free-tier gateway — synchronous capture, no checkout screen,
 * no external call. Useful for free plans/products where the gateway step
 * is a formality.
 */
class FreeGateway implements PaymentGatewayInterface
{
    public function createIntent(PaymentIntentData $data): GatewayResponse
    {
        return new GatewayResponse(
            status: PaymentStatus::Pending,
            reference: 'FREE-' . strtoupper(substr($data->publicId, -8)),
            rawPayload: $data->toArray(),
        );
    }

    public function handleWebhook(array $payload): GatewayResponse
    {
        return new GatewayResponse(
            status: PaymentStatus::Captured,
            reference: $payload['reference'] ?? '',
            rawPayload: $payload,
        );
    }

    public function validateWebhook(Request $request): bool
    {
        return true;
    }
}
