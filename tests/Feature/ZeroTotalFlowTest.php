<?php

declare(strict_types=1);

namespace ForgeStack\IdempotentPayments\Tests\Feature;

use ForgeStack\IdempotentPayments\Actions\CreatePaymentIntent;
use ForgeStack\IdempotentPayments\Drivers\Gateways\FreeGateway;
use ForgeStack\IdempotentPayments\Enums\PaymentStatus;
use ForgeStack\IdempotentPayments\Events\PaymentCaptured;
use ForgeStack\IdempotentPayments\Exceptions\PaymentFailedException;
use ForgeStack\IdempotentPayments\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;

/**
 * P-03b: FreeGateway solo acepta montos de 0 y nunca acepta webhooks desde el
 * exterior. Es un gateway de pruebas (ADR-028, A8).
 */
class ZeroTotalFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_free_gateway_rejects_a_non_zero_amount(): void
    {
        $this->expectException(PaymentFailedException::class);

        (new CreatePaymentIntent(new FreeGateway()))->execute(
            amount: 500.0,
            currency: 'MXN',
            description: 'Should never be free',
            customerEmail: 'test@example.mx',
            customerName: 'Test Customer',
        );
    }

    public function test_free_gateway_rejects_every_external_webhook(): void
    {
        $this->assertFalse(
            (new FreeGateway())->validateWebhook(Request::create('/webhooks/free', 'POST'))
        );
    }

    public function test_free_gateway_accepts_zero_amount_and_leaves_the_intent_pending(): void
    {
        Event::fake([PaymentCaptured::class]);

        $intent = (new CreatePaymentIntent(new FreeGateway()))->execute(
            amount: 0.0,
            currency: 'MXN',
            description: 'Test order',
            customerEmail: 'test@example.mx',
            customerName: 'Test Customer',
        );

        $this->assertSame('free', $intent->provider);
        $this->assertSame(PaymentStatus::Pending, $intent->status);
        Event::assertNotDispatched(PaymentCaptured::class);
    }
}
