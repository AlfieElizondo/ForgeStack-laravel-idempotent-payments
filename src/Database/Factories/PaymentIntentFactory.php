<?php

declare(strict_types=1);

namespace ForgeStack\IdempotentPayments\Database\Factories;

use ForgeStack\IdempotentPayments\Enums\PaymentStatus;
use ForgeStack\IdempotentPayments\Models\PaymentIntent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentIntent>
 */
class PaymentIntentFactory extends Factory
{
    protected $model = PaymentIntent::class;

    public function definition(): array
    {
        return [
            'amount'             => $this->faker->randomElement([500, 900, 1200, 1500]),
            'currency'           => 'MXN',
            'provider'           => 'fake',
            'provider_reference' => 'FAKE-' . $this->faker->unique()->numerify('########'),
            'status'             => PaymentStatus::Pending,
        ];
    }

    public function captured(): static
    {
        return $this->state(['status' => PaymentStatus::Captured]);
    }

    public function failed(): static
    {
        return $this->state(['status' => PaymentStatus::Failed]);
    }
}
