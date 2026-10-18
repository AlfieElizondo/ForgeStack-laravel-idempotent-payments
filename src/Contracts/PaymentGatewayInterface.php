<?php

declare(strict_types=1);

namespace ForgeStack\IdempotentPayments\Contracts;

use ForgeStack\IdempotentPayments\DTOs\GatewayResponse;
use ForgeStack\IdempotentPayments\DTOs\PaymentIntentData;
use Illuminate\Http\Request;

interface PaymentGatewayInterface
{
    /**
     * Creates a payment intent with the provider.
     * Returns a normalized response with the checkout URL.
     */
    public function createIntent(PaymentIntentData $data): GatewayResponse;

    /**
     * Processes an incoming webhook payload.
     * Returns a normalized response with the real payment status.
     */
    public function handleWebhook(array $payload): GatewayResponse;

    /**
     * Verifies the authenticity of an incoming webhook request.
     *
     * Each provider defines its own mechanism (HMAC signature, Basic Auth,
     * IP allowlist, ...). The caller must call this BEFORE processing the
     * payload — on false, respond 401 without touching storage.
     */
    public function validateWebhook(Request $request): bool;
}
