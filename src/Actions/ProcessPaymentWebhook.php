<?php

declare(strict_types=1);

namespace ForgeStack\IdempotentPayments\Actions;

use ForgeStack\IdempotentPayments\Contracts\PaymentGatewayInterface;
use ForgeStack\IdempotentPayments\Enums\PaymentStatus;
use ForgeStack\IdempotentPayments\Events\PaymentAmountMismatch;
use ForgeStack\IdempotentPayments\Events\PaymentCaptured;
use ForgeStack\IdempotentPayments\Events\PaymentFailed;
use ForgeStack\IdempotentPayments\Events\PaymentRefunded;
use ForgeStack\IdempotentPayments\Models\PaymentEvent;
use ForgeStack\IdempotentPayments\Models\PaymentIntent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Processes an incoming payment webhook. Idempotent via a UNIQUE constraint
 * on payment_events(provider, event_id), where the stored event_id is
 * "{event_id}:{status}" (P-01) — a duplicate webhook delivery
 * (common with every real gateway) hits the UNIQUE, the insert fails
 * silently, and nothing gets mutated or re-dispatched twice. This is a DB
 * guarantee, not a status check — no race condition under concurrent
 * delivery of the same event.
 *
 * Expected payload shape:
 *   - gateway:    PaymentGatewayInterface (already resolved by the caller)
 *   - provider:   string ('mercadopago', 'openpay', 'fake', ...)
 *   - event_id:   string (the provider's event/payment ID)
 *   - event_type: string ('payment', 'charge.succeeded', ...)
 *   - payload:    array (raw webhook body, forwarded to gateway->handleWebhook)
 *
 * Does NOT mutate anything beyond the PaymentIntent itself — no concept of
 * an order, a submission, or a user. Listen for PaymentCaptured/PaymentFailed
 * in your own app to react to the outcome.
 */
final class ProcessPaymentWebhook
{
    public function execute(array $payload): void
    {
        $gateway   = $payload['gateway'];
        $provider  = $payload['provider'];
        $eventId   = $payload['event_id'];
        $eventType = $payload['event_type'];
        $rawBody   = $payload['payload'] ?? [];

        if (! $gateway instanceof PaymentGatewayInterface) {
            throw new \InvalidArgumentException('payload[gateway] must be PaymentGatewayInterface');
        }

        $response = $gateway->handleWebhook($rawBody);

        DB::transaction(function () use (
            $provider, $eventId, $eventType, $rawBody, $response
        ) {
            try {
                // P-01: la clave incluye el estado. El mismo aviso con el mismo estado es duplicado;
                // el mismo id con un estado nuevo (pendiente → aprobado) se procesa.
                $event = PaymentEvent::create([
                    'provider'     => $provider,
                    'event_id'     => $eventId . ':' . $response->status->value,
                    'event_type'   => $eventType,
                    'processed_at' => now(),
                    'payload'      => $rawBody,
                ]);
            } catch (UniqueConstraintViolationException) {
                Log::info('[IdempotentPayments] Duplicate webhook ignored', [
                    'provider' => $provider,
                    'event_id' => $eventId,
                ]);
                return;
            }

            // P-11: primero por public_id (external_reference); si la pasarela no lo trae, por provider_reference.
            $intent = match (true) {
                $response->intentPublicId !== null => PaymentIntent::where('public_id', $response->intentPublicId)->lockForUpdate()->first(),
                $response->reference !== ''        => PaymentIntent::where('provider_reference', $response->reference)->lockForUpdate()->first(),
                default                            => null,
            };

            if (! $intent) {
                Log::warning('[IdempotentPayments] Webhook with no matching intent', [
                    'provider'  => $provider,
                    'event_id'  => $eventId,
                    'reference' => $response->reference,
                ]);
                return;
            }

            $event->update(['payment_intent_id' => $intent->id]);

            // P-08: solo Captured y Refunded son terminales. Un rechazo no lo es: en Checkout Pro el
            // comprador puede reintentar con otra tarjeta y la aprobación llega después (Failed → Captured).
            // S-08: un reembolso o contracargo después de la captura sí cambia el intent (una sola vez).
            if ($intent->status === PaymentStatus::Captured && $response->isRefunded()) {
                $intent->update([
                    'status'           => PaymentStatus::Refunded,
                    'gateway_response' => $response->rawPayload,
                ]);

                PaymentRefunded::dispatch($intent);

                return;
            }

            if ($intent->status === PaymentStatus::Captured || $intent->status === PaymentStatus::Refunded) {
                return;
            }

            // Un aviso de reembolso para un cobro que nunca se capturó no cambia nada.
            if ($response->isRefunded()) {
                return;
            }

            if ($intent->status === PaymentStatus::Failed && $response->isFailed()) {
                return; // otro rechazo: PaymentFailed ya se despachó
            }

            // S-09: una aprobación por otro monto u otra moneda no se captura.
            if ($response->isSuccessful() && ! self::amountMatches($intent, $response)) {
                Log::warning('[IdempotentPayments] Paid amount does not match the intent; not captured', [
                    'intent'   => $intent->public_id,
                    'expected' => $intent->amount . ' ' . $intent->currency,
                    'paid'     => $response->paidAmount . ' ' . $response->paidCurrency,
                ]);

                PaymentAmountMismatch::dispatch($intent, (string) $response->paidAmount, $response->paidCurrency);

                return;
            }

            if ($response->isSuccessful()) {
                $intent->update([
                    'status'           => PaymentStatus::Captured,
                    'gateway_response' => $response->rawPayload,
                ]);

                PaymentCaptured::dispatch($intent);

            } elseif ($response->isFailed()) {
                $intent->update([
                    'status'           => PaymentStatus::Failed,
                    'gateway_response' => $response->rawPayload,
                ]);

                PaymentFailed::dispatch($intent, $response->errorMessage ?? 'Webhook failed');
            }
            // Pending → don't mutate; wait for the next event.
        });
    }

    /** Sin monto reportado no hay con qué comparar; con monto, se compara en centavos y la moneda sin distinguir mayúsculas. */
    private static function amountMatches(PaymentIntent $intent, \ForgeStack\IdempotentPayments\DTOs\GatewayResponse $response): bool
    {
        if ($response->paidAmount === null) {
            return true;
        }

        if (! is_numeric($response->paidAmount)) {
            return false;
        }

        $sameAmount = (int) round((float) $response->paidAmount * 100) === (int) round((float) $intent->amount * 100);
        $sameCurrency = $response->paidCurrency === null
            || strtoupper($response->paidCurrency) === strtoupper((string) $intent->currency);

        return $sameAmount && $sameCurrency;
    }
}
