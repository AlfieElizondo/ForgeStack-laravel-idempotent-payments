<?php

declare(strict_types=1);

namespace ForgeStack\IdempotentPayments\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable log of processed webhook events.
 *
 * Idempotency guarantee lives in the DB: UNIQUE (provider, event_id). No
 * domain logic here — it's an append-only log.
 *
 * @property int $id
 * @property int|null $payment_intent_id
 * @property string $provider
 * @property string $event_id
 * @property string $event_type
 * @property \Carbon\CarbonImmutable $processed_at
 * @property array|null $payload
 */
class PaymentEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_intent_id',
        'provider',
        'event_id',
        'event_type',
        'processed_at',
        'payload',
    ];

    protected $casts = [
        'processed_at' => 'immutable_datetime',
        'payload'      => 'array',
    ];

    public function paymentIntent(): BelongsTo
    {
        return $this->belongsTo(PaymentIntent::class);
    }

    protected static function newFactory()
    {
        return \ForgeStack\IdempotentPayments\Database\Factories\PaymentEventFactory::new();
    }
}
