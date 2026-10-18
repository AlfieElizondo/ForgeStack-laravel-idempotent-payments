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
 * on payment_events(provider, event_id) — a duplicate webhook delivery
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
                $event = PaymentEvent::create([
                    'provider'     => $provider,
                    'event_id'     => $eventId,
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

            $intent = $response->reference
                ? PaymentIntent::where('provider_reference', $response->reference)->first()
                : null;

            if (! $intent) {
                Log::warning('[IdempotentPayments] Webhook with no matching intent', [
                    'provider'  => $provider,
                    'event_id'  => $eventId,
                    'reference' => $response->reference,
                ]);
                return;
            }

            $event->update(['payment_intent_id' => $intent->id]);

            if ($intent->status === PaymentStatus::Captured || $intent->status === PaymentStatus::Failed) {
                return; // already terminal — never re-mutate
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
