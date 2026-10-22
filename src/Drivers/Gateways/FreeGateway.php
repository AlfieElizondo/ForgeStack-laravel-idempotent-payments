<?php

declare(strict_types=1);

namespace ForgeStack\IdempotentPayments\Drivers\Gateways;

use ForgeStack\IdempotentPayments\Contracts\PaymentGatewayInterface;
use ForgeStack\IdempotentPayments\DTOs\GatewayResponse;
use ForgeStack\IdempotentPayments\DTOs\PaymentIntentData;
use ForgeStack\IdempotentPayments\Enums\PaymentStatus;
use ForgeStack\IdempotentPayments\Exceptions\PaymentFailedException;
use Illuminate\Http\Request;

/**
 * Gateway de pruebas para totales de 0. No está pensado para producción: la
 * tienda no tiene pedidos de total 0 (ADR-028, A8). Solo acepta un monto
 * exactamente 0 y nunca acepta webhooks desde el exterior, porque no hay un
 * proveedor que verifique su firma (P-03b).
 */
class FreeGateway implements PaymentGatewayInterface
{
    public function createIntent(PaymentIntentData $data): GatewayResponse
    {
        if ($data->amount !== 0.0) {
            throw PaymentFailedException::becauseGatewayRejected(
                'FreeGateway only accepts a zero amount.'
            );
        }

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
        return false;
    }
}
