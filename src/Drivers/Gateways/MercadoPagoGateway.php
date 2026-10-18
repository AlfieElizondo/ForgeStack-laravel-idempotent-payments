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
 * MercadoPago gateway (Checkout Preferences / hosted redirect flow).
 *
 * Flow:
 *  1. createIntent()  → POST /checkout/preferences → returns preference_id + init_point.
 *  2. MP calls your webhook when the payment changes state.
 *  3. handleWebhook() → GET /v1/payments/{id} → normalizes status (never trusts the raw body).
 *  4. validateWebhook() → verifies the HMAC X-Signature header.
 *
 * `$successUrl`/`$failureUrl`/`$pendingUrl`/`$notificationUrl` are plain
 * strings your app resolves (e.g. via `route()`) and passes in — this
 * package has no concept of Laravel routes.
 *
 * In-page Checkout Bricks (card tokenization widget) is a separate concern
 * from this gateway — it belongs in your own app's infrastructure layer,
 * not in a generic payments package.
 *
 * @see https://www.mercadopago.com.mx/developers/es/docs/checkout-api/landing
 */
final class MercadoPagoGateway implements PaymentGatewayInterface
{
    private const API_BASE = 'https://api.mercadopago.com';

    public function __construct(
        private readonly string $accessToken,
        private readonly string $webhookSecret,
        private readonly string $successUrl = '',
        private readonly string $failureUrl = '',
        private readonly string $pendingUrl = '',
        private readonly string $notificationUrl = '',
    ) {}

    public function createIntent(PaymentIntentData $data): GatewayResponse
    {
        try {
            $response = Http::withToken($this->accessToken)
                ->post(self::API_BASE . '/checkout/preferences', [
                    'external_reference' => $data->publicId,
                    'items' => [
                        [
                            'id'          => $data->publicId,
                            'title'       => $data->description,
                            'quantity'    => 1,
                            'unit_price'  => $data->amount,
                            'currency_id' => strtoupper($data->currency),
                        ],
                    ],
                    'payer' => [
                        'email' => $data->customerEmail,
                        'name'  => $data->customerName,
                    ],
                    'back_urls' => [
                        'success' => $this->successUrl,
                        'failure' => $this->failureUrl,
                        'pending' => $this->pendingUrl,
                    ],
                    'auto_return'          => 'approved',
                    'notification_url'     => $this->notificationUrl,
                ]);

            if (! $response->successful()) {
                Log::error('[MercadoPago] createIntent failed', [
                    'status'   => $response->status(),
                    'body'     => $response->body(),
                    'publicId' => $data->publicId,
                ]);

                throw PaymentFailedException::becauseGatewayRejected(
                    'mercadopago: ' . $response->json('message', 'Unknown error'),
                );
            }

            $body = $response->json();

            return new GatewayResponse(
                status:      PaymentStatus::Pending,
                reference:   $body['id'],
                checkoutUrl: $body['init_point'],
                rawPayload:  $body,
            );

        } catch (PaymentFailedException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('[MercadoPago] createIntent exception', [
                'message'  => $e->getMessage(),
                'publicId' => $data->publicId,
            ]);
            throw PaymentFailedException::becauseGatewayRejected('mercadopago: ' . $e->getMessage());
        }
    }

    public function handleWebhook(array $payload): GatewayResponse
    {
        // MP's notification only carries an ID — we always re-fetch the
        // payment from the API rather than trusting the webhook body.
        $paymentId = $payload['data']['id'] ?? $payload['id'] ?? null;

        if (! $paymentId) {
            Log::warning('[MercadoPago] Webhook received without payment ID', $payload);
            return new GatewayResponse(
                status:     PaymentStatus::Pending,
                reference:  '',
                rawPayload: $payload,
            );
        }

        try {
            $response = Http::withToken($this->accessToken)
                ->get(self::API_BASE . "/v1/payments/{$paymentId}");

            if (! $response->successful()) {
                Log::error('[MercadoPago] handleWebhook: payment lookup failed', [
                    'payment_id' => $paymentId,
                    'status'     => $response->status(),
                ]);
                return new GatewayResponse(
                    status:     PaymentStatus::Pending,
                    reference:  (string) $paymentId,
                    rawPayload: $payload,
                );
            }

            $payment = $response->json();

            return new GatewayResponse(
                status:     $this->mapStatus($payment['status'] ?? ''),
                reference:  (string) $paymentId,
                rawPayload: $payment,
            );

        } catch (Throwable $e) {
            Log::error('[MercadoPago] handleWebhook exception', [
                'payment_id' => $paymentId,
                'message'    => $e->getMessage(),
            ]);
            return new GatewayResponse(
                status:     PaymentStatus::Pending,
                reference:  (string) $paymentId,
                rawPayload: $payload,
            );
        }
    }

    /**
     * MP sends: x-signature: ts=<timestamp>,v1=<hmac>
     * Signed manifest: "id:<data.id>;request-id:<x-request-id>;ts:<ts>;"
     *
     * @see https://www.mercadopago.com.mx/developers/es/docs/your-integrations/notifications/webhooks
     */
    public function validateWebhook(Request $request): bool
    {
        if (empty($this->webhookSecret)) {
            return true;
        }

        $xSignature = $request->header('x-signature', '');
        $xRequestId = $request->header('x-request-id', '');
        $dataId     = (string) $request->input('data.id', '');

        $parts = [];
        foreach (explode(',', $xSignature) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            $parts[trim($key)] = trim($value);
        }

        $ts = $parts['ts'] ?? '';
        $v1 = $parts['v1'] ?? '';

        if (! $ts || ! $v1) {
            return false;
        }

        $manifest = "id:{$dataId};request-id:{$xRequestId};ts:{$ts};";
        $expected = hash_hmac('sha256', $manifest, $this->webhookSecret);

        return hash_equals($expected, $v1);
    }

    private function mapStatus(string $mpStatus): PaymentStatus
    {
        return match ($mpStatus) {
            'approved'      => PaymentStatus::Captured,
            'pending',
            'in_process',
            'authorized'    => PaymentStatus::Pending,
            'rejected',
            'cancelled',
            'refunded',
            'charged_back'  => PaymentStatus::Failed,
            default         => PaymentStatus::Pending,
        };
    }
}
