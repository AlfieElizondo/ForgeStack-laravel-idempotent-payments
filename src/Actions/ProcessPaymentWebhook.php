<?php

declare(strict_types=1);

namespace ForgeStack\IdempotentPayments\Actions;

use ForgeStack\IdempotentPayments\Contracts\PaymentGatewayInterface;
use ForgeStack\IdempotentPayments\Enums\PaymentStatus;
use ForgeStack\IdempotentPayments\Events\PaymentCaptured;
use ForgeStack\IdempotentPayments\Events\PaymentFailed;
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
            if ($intent->status === PaymentStatus::Captured || $intent->status === PaymentStatus::Refunded) {
                return;
            }

            if ($intent->status === PaymentStatus::Failed && $response->isFailed()) {
                return; // otro rechazo: PaymentFailed ya se despachó
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
}
