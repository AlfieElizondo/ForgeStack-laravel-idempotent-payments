<?php

declare(strict_types=1);

namespace ForgeStack\IdempotentPayments\Database\Factories;

use ForgeStack\IdempotentPayments\Models\PaymentEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentEvent>
 */
class PaymentEventFactory extends Factory
{
    protected $model = PaymentEvent::class;

    public function definition(): array
    {
        return [
            'payment_intent_id' => null,
            'provider'          => 'fake',
            'event_id'          => 'evt_' . $this->faker->unique()->numerify('##########'),
            'event_type'        => 'payment',
            'processed_at'      => now(),
            'payload'           => [],
        ];
    }
}
