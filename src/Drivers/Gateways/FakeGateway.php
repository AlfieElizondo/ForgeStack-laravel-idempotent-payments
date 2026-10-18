<?php

declare(strict_types=1);

namespace ForgeStack\IdempotentPayments\Drivers\Gateways;

use ForgeStack\IdempotentPayments\Contracts\PaymentGatewayInterface;
use ForgeStack\IdempotentPayments\DTOs\GatewayResponse;
use ForgeStack\IdempotentPayments\DTOs\PaymentIntentData;
use ForgeStack\IdempotentPayments\Enums\PaymentStatus;
use Illuminate\Http\Request;

/**
 * Demo/test gateway — never calls a real provider, always approves on
 * webhook. `$checkoutUrlResolver` is an optional `fn(string $reference): ?string`
 * your app can inject to point at its own confirmation page — the package
 * has no concept of routes.
 */
class FakeGateway implements PaymentGatewayInterface
{
    public function __construct(
        private readonly ?\Closure $checkoutUrlResolver = null,
    ) {}

    public function createIntent(PaymentIntentData $data): GatewayResponse
    {
        if ($data->amount > 0 && ! app()->environment('local', 'testing')) {
            throw new \RuntimeException(
                'FakeGateway cannot process a non-zero amount outside local/testing. ' .
                'Configure a real payment gateway for this context.'
            );
        }

        $reference = 'FAKE-' . strtoupper(substr($data->publicId, -8));

        $checkoutUrl = $this->checkoutUrlResolver
            ? ($this->checkoutUrlResolver)($reference)
            : null;

        return new GatewayResponse(
            status: PaymentStatus::Pending,
            reference: $reference,
            checkoutUrl: $checkoutUrl ?? ('about:blank#' . $reference),
            rawPayload: $data->toArray(),
        );
    }

    public function handleWebhook(array $payload): GatewayResponse
    {
        return new GatewayResponse(
            status: PaymentStatus::Captured,
            reference: $payload['reference'],
            rawPayload: $payload,
        );
    }

    public function validateWebhook(Request $request): bool
    {
        return true;
    }
}
