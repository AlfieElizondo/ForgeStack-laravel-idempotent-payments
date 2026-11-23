<?php

declare(strict_types=1);

namespace ForgeStack\IdempotentPayments\Tests\Feature;

use ForgeStack\IdempotentPayments\Actions\CreatePaymentIntent;
use ForgeStack\IdempotentPayments\Actions\ProcessPaymentWebhook;
use ForgeStack\IdempotentPayments\Contracts\PaymentGatewayInterface;
use ForgeStack\IdempotentPayments\DTOs\GatewayResponse;
use ForgeStack\IdempotentPayments\DTOs\PaymentIntentData;
use ForgeStack\IdempotentPayments\Drivers\Gateways\FakeGateway;
use ForgeStack\IdempotentPayments\Drivers\Gateways\MercadoPagoGateway;
use ForgeStack\IdempotentPayments\Enums\PaymentStatus;
use ForgeStack\IdempotentPayments\Events\PaymentAmountMismatch;
use ForgeStack\IdempotentPayments\Events\PaymentCaptured;
use ForgeStack\IdempotentPayments\Events\PaymentFailed;
use ForgeStack\IdempotentPayments\Events\PaymentRefunded;
use ForgeStack\IdempotentPayments\Models\PaymentIntent;
use ForgeStack\IdempotentPayments\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * P-11, P-08 y P-01: el webhook encuentra su intent y aplica el estado correcto
 * aunque lleguen varios avisos del mismo cobro.
 */
class WebhookReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_mercadopago_webhook_finds_its_intent_through_the_external_reference(): void
    {
        // P-11: el intent guarda el id de la preferencia; el webhook trae el id del pago.
        Http::fake([
            'api.mercadopago.com/checkout/preferences' => Http::response(['id' => 'pref-1', 'init_point' => 'https://mp.test/pay'], 201),
        ]);

        $gateway = new MercadoPagoGateway(accessToken: 'TEST-token', webhookSecret: 'secret');
        $intent = (new CreatePaymentIntent($gateway))->execute(
            amount: 1500.0,
            currency: 'MXN',
            description: 'Pedido',
            customerEmail: 'cliente@example.mx',
            customerName: 'Cliente',
        );
        $this->assertSame('pref-1', $intent->provider_reference);

        Http::fake([
            'api.mercadopago.com/v1/payments/999' => Http::response([
                'id' => 999,
                'status' => 'approved',
                'external_reference' => $intent->public_id,
            ]),
        ]);
        Event::fake([PaymentCaptured::class]);

        (new ProcessPaymentWebhook())->execute([
            'gateway' => $gateway,
            'provider' => 'mercadopago',
            'event_id' => 'notification-1',
            'event_type' => 'payment',
            'payload' => ['type' => 'payment', 'data' => ['id' => '999']],
        ]);

        $this->assertSame(PaymentStatus::Captured, $intent->fresh()->status);
        Event::assertDispatchedTimes(PaymentCaptured::class, 1);
    }

    public function test_an_approval_after_a_rejection_captures_the_intent(): void
    {
        // P-08: en Checkout Pro el comprador reintenta con otra tarjeta dentro de la misma preferencia.
        $intent = $this->fakeIntent();
        Event::fake([PaymentCaptured::class, PaymentFailed::class]);

        $this->deliver($intent, 'evt-rejected', PaymentStatus::Failed);
        $this->assertSame(PaymentStatus::Failed, $intent->fresh()->status);

        $this->deliver($intent, 'evt-approved', PaymentStatus::Captured);

        $this->assertSame(PaymentStatus::Captured, $intent->fresh()->status);
        Event::assertDispatchedTimes(PaymentFailed::class, 1);
        Event::assertDispatchedTimes(PaymentCaptured::class, 1);
    }

    public function test_a_rejection_after_a_capture_never_reverts_it(): void
    {
        $intent = $this->fakeIntent();
        Event::fake([PaymentCaptured::class, PaymentFailed::class]);

        $this->deliver($intent, 'evt-approved', PaymentStatus::Captured);
        $this->deliver($intent, 'evt-rejected', PaymentStatus::Failed);

        $this->assertSame(PaymentStatus::Captured, $intent->fresh()->status);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    public function test_a_second_rejection_does_not_dispatch_payment_failed_again(): void
    {
        $intent = $this->fakeIntent();
        Event::fake([PaymentFailed::class]);

        $this->deliver($intent, 'evt-rejected-1', PaymentStatus::Failed);
        $this->deliver($intent, 'evt-rejected-2', PaymentStatus::Failed);

        Event::assertDispatchedTimes(PaymentFailed::class, 1);
    }

    public function test_the_same_event_id_with_a_new_status_is_not_discarded_as_a_duplicate(): void
    {
        // P-01: el mismo id llega primero como pendiente y después como aprobado.
        $intent = $this->fakeIntent();
        Event::fake([PaymentCaptured::class]);

        $this->deliver($intent, 'evt-1', PaymentStatus::Pending);
        $this->deliver($intent, 'evt-1', PaymentStatus::Captured);
        $this->deliver($intent, 'evt-1', PaymentStatus::Captured);

        $this->assertSame(PaymentStatus::Captured, $intent->fresh()->status);
        Event::assertDispatchedTimes(PaymentCaptured::class, 1);
        $this->assertDatabaseCount('payment_events', 2);
    }

    private function fakeIntent(): PaymentIntent
    {
        return (new CreatePaymentIntent(new FakeGateway()))->execute(
            amount: 500.0,
            currency: 'MXN',
            description: 'Pedido',
            customerEmail: 'cliente@example.mx',
            customerName: 'Cliente',
        );
    }

    private function deliver(PaymentIntent $intent, string $eventId, PaymentStatus $status): void
    {
        (new ProcessPaymentWebhook())->execute([
            'gateway' => $this->gatewayReturning($intent->provider_reference, $status),
            'provider' => 'stub',
            'event_id' => $eventId,
            'event_type' => 'payment',
            'payload' => [],
        ]);
    }

    private function gatewayReturning(string $reference, PaymentStatus $status): PaymentGatewayInterface
    {
        return new class($reference, $status) implements PaymentGatewayInterface
        {
            public function __construct(private string $reference, private PaymentStatus $status) {}

            public function createIntent(PaymentIntentData $data): GatewayResponse
            {
                throw new \LogicException('no se usa');
            }

            public function handleWebhook(array $payload): GatewayResponse
            {
                return new GatewayResponse(status: $this->status, reference: $this->reference);
            }

            public function validateWebhook(Request $request): bool
            {
                return true;
            }
        };
    }

    public function test_a_refund_after_a_capture_marks_the_intent_refunded_and_dispatches_an_event(): void
    {
        // S-08: Captured ya no es sordo a reembolsos y contracargos.
        $intent = $this->fakeIntent();
        Event::fake([PaymentCaptured::class, PaymentFailed::class, PaymentRefunded::class]);

        $this->deliver($intent, 'evt-approved', PaymentStatus::Captured);
        $this->deliver($intent, 'evt-refunded', PaymentStatus::Refunded);

        $this->assertSame(PaymentStatus::Refunded, $intent->fresh()->status);
        Event::assertDispatchedTimes(PaymentRefunded::class, 1);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    public function test_a_refund_notice_for_an_intent_that_was_never_captured_changes_nothing(): void
    {
        $intent = $this->fakeIntent();
        Event::fake([PaymentRefunded::class, PaymentFailed::class]);

        $this->deliver($intent, 'evt-refunded', PaymentStatus::Refunded);

        $this->assertSame(PaymentStatus::Pending, $intent->fresh()->status);
        Event::assertNotDispatched(PaymentRefunded::class);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    public function test_a_repeated_refund_notice_dispatches_the_event_once(): void
    {
        $intent = $this->fakeIntent();
        Event::fake([PaymentRefunded::class]);

        $this->deliver($intent, 'evt-approved', PaymentStatus::Captured);
        $this->deliver($intent, 'evt-refunded-1', PaymentStatus::Refunded);
        $this->deliver($intent, 'evt-refunded-2', PaymentStatus::Refunded);

        Event::assertDispatchedTimes(PaymentRefunded::class, 1);
    }

    public function test_mercadopago_refunds_and_chargebacks_map_to_refunded(): void
    {
        $gateway = new MercadoPagoGateway(accessToken: 'TEST-token', webhookSecret: 'secret');

        foreach (['refunded' => 1, 'charged_back' => 2] as $mpStatus => $id) {
            Http::fake(["api.mercadopago.com/v1/payments/{$id}" => Http::response(['id' => $id, 'status' => $mpStatus, 'external_reference' => 'x'])]);

            $response = $gateway->handleWebhook(['type' => 'payment', 'data' => ['id' => (string) $id]]);

            $this->assertSame(PaymentStatus::Refunded, $response->status, $mpStatus);
        }
    }

    private function deliverPaid(PaymentIntent $intent, string $eventId, ?string $amount, ?string $currency): void
    {
        $gateway = new class($intent->provider_reference, $amount, $currency) implements PaymentGatewayInterface
        {
            public function __construct(private string $reference, private ?string $amount, private ?string $currency) {}

            public function createIntent(PaymentIntentData $data): GatewayResponse
            {
                throw new \LogicException('no se usa');
            }

            public function handleWebhook(array $payload): GatewayResponse
            {
                return new GatewayResponse(status: PaymentStatus::Captured, reference: $this->reference, paidAmount: $this->amount, paidCurrency: $this->currency);
            }

            public function validateWebhook(Request $request): bool
            {
                return true;
            }
        };

        (new ProcessPaymentWebhook())->execute([
            'gateway' => $gateway, 'provider' => 'stub', 'event_id' => $eventId, 'event_type' => 'payment', 'payload' => [],
        ]);
    }

    public function test_an_approval_for_a_different_amount_is_not_captured(): void
    {
        // S-09: el intent es de 500.00 MXN; la pasarela reporta 5.00.
        $intent = $this->fakeIntent();
        Event::fake([PaymentCaptured::class, PaymentAmountMismatch::class]);

        $this->deliverPaid($intent, 'evt-1', '5.00', 'MXN');

        $this->assertSame(PaymentStatus::Pending, $intent->fresh()->status);
        Event::assertNotDispatched(PaymentCaptured::class);
        Event::assertDispatched(PaymentAmountMismatch::class, fn ($e) => $e->paidAmount === '5.00' && $e->paidCurrency === 'MXN');
    }

    public function test_an_approval_in_a_different_currency_is_not_captured(): void
    {
        $intent = $this->fakeIntent();
        Event::fake([PaymentCaptured::class, PaymentAmountMismatch::class]);

        $this->deliverPaid($intent, 'evt-1', '500.00', 'USD');

        $this->assertSame(PaymentStatus::Pending, $intent->fresh()->status);
        Event::assertDispatched(PaymentAmountMismatch::class);
    }

    public function test_an_approval_for_the_exact_amount_is_captured_even_without_decimals(): void
    {
        $intent = $this->fakeIntent();
        Event::fake([PaymentCaptured::class, PaymentAmountMismatch::class]);

        $this->deliverPaid($intent, 'evt-1', '500', 'mxn');

        $this->assertSame(PaymentStatus::Captured, $intent->fresh()->status);
        Event::assertNotDispatched(PaymentAmountMismatch::class);
    }

    public function test_a_gateway_that_does_not_report_the_amount_still_captures(): void
    {
        $intent = $this->fakeIntent();
        Event::fake([PaymentCaptured::class]);

        $this->deliverPaid($intent, 'evt-1', null, null);

        $this->assertSame(PaymentStatus::Captured, $intent->fresh()->status);
    }

    public function test_mercadopago_reports_the_paid_amount_and_currency(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments/7' => Http::response(['id' => 7, 'status' => 'approved', 'transaction_amount' => 1740.5, 'currency_id' => 'MXN', 'external_reference' => 'x'])]);

        $response = (new MercadoPagoGateway(accessToken: 'TEST-token', webhookSecret: 'secret'))->handleWebhook(['type' => 'payment', 'data' => ['id' => '7']]);

        $this->assertSame('1740.5', $response->paidAmount);
        $this->assertSame('MXN', $response->paidCurrency);
    }
}
