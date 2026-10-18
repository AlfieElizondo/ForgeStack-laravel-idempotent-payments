<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * payment_events — cross-cutting idempotency.
 *
 * Every incoming webhook inserts a row with UNIQUE(provider, event_id). A
 * duplicate delivery hits the UNIQUE and the transaction aborts silently —
 * this replaces a status-check guard in ProcessPaymentWebhook and removes
 * the theoretical race condition under concurrent delivery.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_intent_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->string('provider');
            $table->string('event_id');
            $table->string('event_type');
            $table->timestamp('processed_at')->useCurrent();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'event_id'], 'payment_events_provider_event_id_unique');
            $table->index('payment_intent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_events');
    }
};
