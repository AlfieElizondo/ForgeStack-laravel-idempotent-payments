<?php

declare(strict_types=1);

namespace ForgeStack\IdempotentPayments\Actions;

use ForgeStack\IdempotentPayments\Contracts\PaymentGatewayInterface;
use ForgeStack\IdempotentPayments\DTOs\PaymentIntentData;
use ForgeStack\IdempotentPayments\Drivers\Gateways\FakeGateway;
use ForgeStack\IdempotentPayments\Drivers\Gateways\FreeGateway;
use ForgeStack\IdempotentPayments\Drivers\Gateways\MercadoPagoGateway;
use ForgeStack\IdempotentPayments\Drivers\Gateways\OpenPayGateway;
use ForgeStack\IdempotentPayments\Enums\PaymentStatus;
use ForgeStack\IdempotentPayments\Exceptions\PaymentFailedException;
use ForgeStack\IdempotentPayments\Models\PaymentIntent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates a generic payment intent. Has no concept of an order, a user, or
 * who is paying for what beyond the plain fields below — your app links
 * the returned PaymentIntent to its own model via its own foreign key.
 */
final class CreatePaymentIntent
{
    public function __construct(
        private readonly PaymentGatewayInterface $gateway,
    ) {}

    public function execute(
        float $amount,
        string $currency,
        string $description,
        string $customerEmail,
        string $customerName,
    ): PaymentIntent {
        $data = new PaymentIntentData(
            publicId:      (string) Str::ulid(),
            amount:        $amount,
            currency:      $currency,
            description:   $description,
            customerEmail: $customerEmail,
            customerName:  $customerName,
        );

        $response = $this->gateway->createIntent($data);

        if ($response->isFailed()) {
            throw PaymentFailedException::becauseGatewayRejected(
                $response->errorMessage ?? 'Unknown error'
            );
        }

        $providerName = match (true) {
            $this->gateway instanceof FreeGateway        => 'free',
            $this->gateway instanceof FakeGateway         => 'fake',
            $this->gateway instanceof MercadoPagoGateway  => 'mercadopago',
            $this->gateway instanceof OpenPayGateway      => 'openpay',
            default => 'unknown',
        };

        $rawPayload = array_merge($response->rawPayload, [
            'checkout_url' => $response->checkoutUrl,
        ]);

        return DB::transaction(function () use ($data, $response, $providerName, $rawPayload) {
            return PaymentIntent::create([
                'public_id'          => $data->publicId,
                'amount'             => $data->amount,
                'currency'           => $data->currency,
                'provider'           => $providerName,
                'provider_reference' => $response->reference,
                'status'             => PaymentStatus::Pending,
                'gateway_response'   => $rawPayload,
            ]);
        });
    }
}
