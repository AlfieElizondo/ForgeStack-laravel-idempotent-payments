<?php

declare(strict_types=1);

namespace ForgeStack\IdempotentPayments\Tests\Feature;

use ForgeStack\IdempotentPayments\Actions\CreatePaymentIntent;
use ForgeStack\IdempotentPayments\Actions\ProcessPaymentWebhook;
use ForgeStack\IdempotentPayments\Drivers\Gateways\FakeGateway;
use ForgeStack\IdempotentPayments\Drivers\Gateways\MercadoPagoGateway;
use ForgeStack\IdempotentPayments\Drivers\Gateways\OpenPayGateway;
use ForgeStack\IdempotentPayments\Enums\PaymentStatus;
use ForgeStack\IdempotentPayments\Events\PaymentCaptured;
use ForgeStack\IdempotentPayments\Exceptions\PaymentFailedException;
use ForgeStack\IdempotentPayments\Models\PaymentIntent;
use ForgeStack\IdempotentPayments\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

class PaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_payment_intent_with_fake_gateway(): void
    {
        $intent = (new CreatePaymentIntent(new FakeGateway()))->execute(
            amount: 500.0,
            currency: 'MXN',
            description: 'Test charge',
            customerEmail: 'test@example.mx',
            customerName: 'Test Customer',
        );

        $this->assertInstanceOf(PaymentIntent::class, $intent);
        $this->assertSame(PaymentStatus::Pending, $intent->status);
        $this->assertStringStartsWith('FAKE-', $intent->provider_reference);
        $this->assertDatabaseHas('payment_intents', ['id' => $intent->id]);
    }

    public function test_fake_gateway_rejects_non_zero_amount_outside_local_or_testing(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(\RuntimeException::class);

        (new CreatePaymentIntent(new FakeGateway()))->execute(
            amount: 500.0,
            currency: 'MXN',
            description: 'Test charge',
            customerEmail: 'test@example.mx',
            customerName: 'Test Customer',
        );
    }

    public function test_fake_gateway_checkout_url_resolver_is_used(): void
    {
        $gateway = new FakeGateway(
            checkoutUrlResolver: fn (string $reference) => "https://host.test/confirm/{$reference}",
        );

        $intent = (new CreatePaymentIntent($gateway))->execute(
            amount: 0.0,
            currency: 'MXN',
            description: 'Free',
            customerEmail: 'test@example.mx',
            customerName: 'Test Customer',
        );

        $this->assertStringContainsString('https://host.test/confirm/', $intent->gateway_response['checkout_url']);
    }

    public function test_webhook_captures_the_intent_and_dispatches_payment_captured(): void
    {
        Event::fake([PaymentCaptured::class]);

        $gateway = new FakeGateway();
        $intent = (new CreatePaymentIntent($gateway))->execute(
            amount: 0.0,
            currency: 'MXN',
            description: 'Free',
            customerEmail: 'test@example.mx',
            customerName: 'Test Customer',
        );

        (new ProcessPaymentWebhook())->execute([
            'gateway'    => $gateway,
            'provider'   => 'fake',
            'event_id'   => 'evt_' . $intent->provider_reference,
            'event_type' => 'payment',
            'payload'    => ['reference' => $intent->provider_reference],
        ]);

        $intent->refresh();
        $this->assertSame(PaymentStatus::Captured, $intent->status);
        Event::assertDispatched(PaymentCaptured::class, fn ($e) => $e->paymentIntent->id === $intent->id);
    }

    public function test_webhook_is_idempotent_via_unique_constraint_on_event_id(): void
    {
        Event::fake([PaymentCaptured::class]);

        $gateway = new FakeGateway();
        $intent  = (new CreatePaymentIntent($gateway))->execute(
            amount: 0.0,
            currency: 'MXN',
            description: 'Free',
            customerEmail: 'test@example.mx',
            customerName: 'Test Customer',
        );

        $webhookPayload = [
            'gateway'    => $gateway,
            'provider'   => 'fake',
            'event_id'   => 'evt_dup_001',
            'event_type' => 'payment',
            'payload'    => ['reference' => $intent->provider_reference],
        ];

        // First delivery captures the intent.
        (new ProcessPaymentWebhook())->execute($webhookPayload);
        // Duplicate delivery (same event_id) — hits the UNIQUE constraint, no-op.
        (new ProcessPaymentWebhook())->execute($webhookPayload);

        $this->assertDatabaseCount('payment_events', 1);
        Event::assertDispatchedTimes(PaymentCaptured::class, 1);
    }

    public function test_mercadopago_gateway_creates_a_preference_and_returns_checkout_url(): void
    {
        Http::fake([
            'api.mercadopago.com/checkout/preferences' => Http::response([
                'id'         => 'PREF-001',
                'init_point' => 'https://www.mercadopago.com.mx/checkout/PREF-001',
            ], 200),
        ]);

        $gateway = new MercadoPagoGateway(
            accessToken: 'test-token',
            webhookSecret: '',
            successUrl: 'https://host.test/success',
            failureUrl: 'https://host.test/failure',
            pendingUrl: 'https://host.test/pending',
            notificationUrl: 'https://host.test/webhooks/mercadopago',
        );

        $intent = (new CreatePaymentIntent($gateway))->execute(
            amount: 500.0,
            currency: 'MXN',
            description: 'Test charge',
            customerEmail: 'test@example.mx',
            customerName: 'Test Customer',
        );

        $this->assertSame('mercadopago', $intent->provider);
        $this->assertSame('PREF-001', $intent->provider_reference);
        Http::assertSent(fn ($request) => $request['back_urls']['success'] === 'https://host.test/success');
    }

    public function test_openpay_gateway_rejects_when_gateway_returns_an_error(): void
    {
        Http::fake([
            'sandbox-api.openpay.mx/*' => Http::response([
                'description' => 'Insufficient funds',
            ], 402),
        ]);

        $gateway = new OpenPayGateway(
            merchantId: 'merchant_test',
            privateKey: 'sk_test',
        );

        $this->expectException(PaymentFailedException::class);

        (new CreatePaymentIntent($gateway))->execute(
            amount: 500.0,
            currency: 'MXN',
            description: 'Test charge',
            customerEmail: 'test@example.mx',
            customerName: 'Test Customer',
        );
    }
}
