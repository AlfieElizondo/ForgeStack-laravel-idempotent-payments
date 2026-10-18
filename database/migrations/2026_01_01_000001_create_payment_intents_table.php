<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_intents', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->decimal('amount', 10, 2);
            $table->char('currency', 3)->default('MXN');
            $table->string('provider')->default('fake');
            $table->string('provider_reference')->nullable();
            $table->string('status')->default('pending');
            $table->jsonb('gateway_response')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('provider_reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_intents');
    }
};
