<?php

declare(strict_types=1);

namespace ForgeStack\IdempotentPayments\Models;

use ForgeStack\IdempotentPayments\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The package never knows who owns an intent. Your app's own model (an
 * Order, a Submission, a Booking, ...) holds a foreign key pointing at
 * payment_intents.id — never the other way around. That's what makes it
 * reusable across unrelated apps.
 *
 * @property int $id
 * @property string $public_id
 * @property float $amount
 * @property string $currency
 * @property string $provider
 * @property string|null $provider_reference
 * @property PaymentStatus $status
 * @property array|null $gateway_response
 */
class PaymentIntent extends Model
{
    use HasFactory;

    protected $fillable = [
        'public_id',
        'amount',
        'currency',
        'provider',
        'provider_reference',
        'status',
        'gateway_response',
    ];

    protected function casts(): array
    {
        return [
            'amount'           => 'decimal:2',
            'status'           => PaymentStatus::class,
            'gateway_response' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PaymentIntent $intent) {
            if (empty($intent->public_id)) {
                $intent->public_id = (string) Str::ulid();
            }
        });
    }

    public function isCaptured(): bool
    {
        return $this->status === PaymentStatus::Captured;
    }

    public function isPending(): bool
    {
        return $this->status === PaymentStatus::Pending;
    }

    public function formattedAmount(): string
    {
        return number_format((float) $this->amount, 2) . ' ' . $this->currency;
    }

    protected static function newFactory()
    {
        return \ForgeStack\IdempotentPayments\Database\Factories\PaymentIntentFactory::new();
    }
}
