<?php

declare(strict_types=1);

namespace ForgeStack\IdempotentPayments\Drivers\Gateways;

use ForgeStack\IdempotentPayments\Contracts\PaymentGatewayInterface;
use ForgeStack\IdempotentPayments\DTOs\GatewayResponse;
use ForgeStack\IdempotentPayments\DTOs\PaymentIntentData;
use ForgeStack\IdempotentPayments\Enums\PaymentStatus;
use ForgeStack\IdempotentPayments\Exceptions\PaymentFailedException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * OpenPay (BBVA México) gateway via Hosted Payment Page.
 *
 * Flow:
 *  1. createIntent()  → POST /v1/{merchant_id}/charges → returns payment_method.url.
 *     The payer is redirected to OpenPay's hosted checkout page.
 *  2. OpenPay calls your webhook when the charge changes status.
 *  3. handleWebhook() → GET /v1/{merchant_id}/charges/{id} → normalizes status.
 *
 * `$redirectUrl` is a plain string your app resolves (e.g. via `route()`)
 * — this package has no concept of Laravel routes.
 *
 * OpenPay has no HMAC webhook signature — it authenticates webhooks via
 * HTTP Basic Auth configured in its own dashboard. It also sends a
 * one-time `verification` event with a `verification_code` your webhook
 * endpoint must echo back to confirm ownership (see your app's webhook
 * controller, not this package).
 *
 * @see https://documents.openpay.mx/docs/api
 * @see https://documents.openpay.mx/en/docs/webhooks.html
 */
final class OpenPayGateway implements PaymentGatewayInterface
{
    private const PRODUCTION_BASE = 'https://api.openpay.mx/v1';
    private const SANDBOX_BASE    = 'https://sandbox-api.openpay.mx/v1';

    public function __construct(
        private readonly string $merchantId,
        private readonly string $privateKey,
        private readonly string $environment     = 'sandbox',
        private readonly string $webhookUser     = '',
        private readonly string $webhookPassword = '',
        private readonly string $redirectUrl     = '',
    ) {}

    public function createIntent(PaymentIntentData $data): GatewayResponse
    {
        try {
            $response = Http::withBasicAuth($this->privateKey, '')
                ->post($this->endpoint('/charges'), [
                    'method'       => 'card',
                    'amount'       => round($data->amount, 2),
                    'currency'     => strtoupper($data->currency),
                    'description'  => $data->description,
                    'order_id'     => $data->publicId,
                    'confirm'      => 'false',
                    'send_email'   => 'false',
                    'redirect_url' => $this->redirectUrl,
                    'customer'     => [
                        'email' => $data->customerEmail,
                        'name'  => $data->customerName,
                    ],
                ]);

            if (! $response->successful()) {
                Log::error('[OpenPay] createIntent failed', [
                    'status'   => $response->status(),
                    'body'     => $response->body(),
                    'publicId' => $data->publicId,
                ]);

                throw PaymentFailedException::becauseGatewayRejected(
                    'openpay: ' . $response->json('description', 'Unknown error'),
                );
            }

            $body        = $response->json();
            $chargeId    = $body['id'] ?? '';
            $checkoutUrl = $body['payment_method']['url'] ?? null;

            if (! $checkoutUrl) {
                Log::error('[OpenPay] createIntent: no checkout URL in response', [
                    'body'     => $body,
                    'publicId' => $data->publicId,
                ]);
                throw PaymentFailedException::becauseGatewayRejected('openpay: response has no payment URL');
            }

            return new GatewayResponse(
                status:      PaymentStatus::Pending,
                reference:   $chargeId,
                checkoutUrl: $checkoutUrl,
                rawPayload:  $body,
            );

        } catch (PaymentFailedException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('[OpenPay] createIntent exception', [
                'message'  => $e->getMessage(),
                'publicId' => $data->publicId,
            ]);
            throw PaymentFailedException::becauseGatewayRejected('openpay: ' . $e->getMessage());
        }
    }

    public function handleWebhook(array $payload): GatewayResponse
    {
        $chargeId = $payload['transaction']['id']
            ?? $payload['object']['id']
            ?? $payload['id']
            ?? null;

        if (! $chargeId) {
            Log::warning('[OpenPay] Webhook received without charge ID', $payload);
            return new GatewayResponse(
                status:     PaymentStatus::Pending,
                reference:  '',
                rawPayload: $payload,
            );
        }

        try {
            $response = Http::withBasicAuth($this->privateKey, '')
                ->get($this->endpoint("/charges/{$chargeId}"));

            if (! $response->successful()) {
                Log::error('[OpenPay] handleWebhook: charge lookup failed', [
                    'charge_id' => $chargeId,
                    'status'    => $response->status(),
                ]);
                return new GatewayResponse(
                    status:     PaymentStatus::Pending,
                    reference:  $chargeId,
                    rawPayload: $payload,
                );
            }

            $charge = $response->json();

            return new GatewayResponse(
                status:     $this->mapStatus($charge['status'] ?? ''),
                reference:  $chargeId,
                rawPayload: $charge,
            );

        } catch (Throwable $e) {
            Log::error('[OpenPay] handleWebhook exception', [
                'charge_id' => $chargeId,
                'message'   => $e->getMessage(),
            ]);
            return new GatewayResponse(
                status:     PaymentStatus::Pending,
                reference:  $chargeId,
                rawPayload: $payload,
            );
        }
    }

    public function validateWebhook(Request $request): bool
    {
        if (empty($this->webhookUser) && empty($this->webhookPassword)) {
            return true;
        }

        $user = $request->getUser();
        $pass = $request->getPassword();

        if ($user === null || $pass === null) {
            return false;
        }

        return hash_equals($this->webhookUser, $user)
            && hash_equals($this->webhookPassword, $pass);
    }

    private function endpoint(string $path): string
    {
        $base = $this->environment === 'production' ? self::PRODUCTION_BASE : self::SANDBOX_BASE;
        return "{$base}/{$this->merchantId}" . $path;
    }

    private function mapStatus(string $status): PaymentStatus
    {
        return match ($status) {
            'completed'               => PaymentStatus::Captured,
            'charge_pending',
            'in_progress'             => PaymentStatus::Pending,
            'failed', 'cancelled',
            'refunded', 'chargeback'  => PaymentStatus::Failed,
            default                   => PaymentStatus::Pending,
        };
    }
}
