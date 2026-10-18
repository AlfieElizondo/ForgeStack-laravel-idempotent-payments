# Laravel Idempotent Payments

Create payment intents and process webhooks for LATAM gateways
(MercadoPago, OpenPay) — with pluggable gateway drivers and idempotency
guaranteed at the database level, not by a status check.

The package never knows who is paying for what. Your app's own model (an
`Order`, a `Submission`, a `Booking`, ...) holds a foreign key pointing at
`payment_intents.id` — never the other way around. That's what makes it
reusable across unrelated apps. It also never decides *which* gateway to
use — your app constructs the right driver (with its own credentials, for
whatever "tenant" or "context" means for you) and passes it in.

## Why idempotency is the point

Every real payment gateway redelivers webhooks — on timeout, on retry, on
your own server returning a slow 200. A guard like
`if ($intent->status === Captured) return;` has a race window under
concurrent delivery of the same event. This package avoids that class of
bug entirely: every webhook insert hits a `UNIQUE(provider, event_id)`
constraint in Postgres. A duplicate delivery fails the insert, the
transaction returns early, and nothing gets mutated or dispatched twice —
the guarantee lives in the database engine, not in application logic.

## Install

```bash
composer require forge-stack/laravel-idempotent-payments
php artisan vendor:publish --tag=idempotent-payments-migrations
php artisan migrate
```

## Usage

```php
use ForgeStack\IdempotentPayments\Actions\CreatePaymentIntent;
use ForgeStack\IdempotentPayments\Drivers\Gateways\MercadoPagoGateway;

// You decide which gateway and which credentials — per request, per
// tenant, per product, whatever makes sense for your app.
$gateway = new MercadoPagoGateway(
    accessToken: $tenant->mercadopago_access_token,
    webhookSecret: $tenant->mercadopago_webhook_secret,
    successUrl: route('payment.result', ['status' => 'success']),
    failureUrl: route('payment.result', ['status' => 'failure']),
    pendingUrl: route('payment.result', ['status' => 'pending']),
    notificationUrl: route('webhooks.mercadopago'),
);

$intent = (new CreatePaymentIntent($gateway))->execute(
    amount: 500.0,
    currency: 'MXN',
    description: 'Order #1234',
    customerEmail: $order->customer->email,
    customerName: $order->customer->name,
);

// Your own model links to it:
Order::create([..., 'payment_intent_id' => $intent->id]);

redirect($intent->gateway_response['checkout_url']);
```

Webhook controller (thin — verify, then process):

```php
use ForgeStack\IdempotentPayments\Actions\ProcessPaymentWebhook;

Route::post('/webhooks/mercadopago', function (Request $request, ProcessPaymentWebhook $processor) {
    $gateway = new MercadoPagoGateway(accessToken: ..., webhookSecret: ...);

    if (! $gateway->validateWebhook($request)) {
        return response('Unauthorized', 401);
    }

    $processor->execute([
        'gateway'    => $gateway,
        'provider'   => 'mercadopago',
        'event_id'   => (string) $request->input('data.id'),
        'event_type' => 'payment',
        'payload'    => $request->all(),
    ]);

    return response('OK', 200);
})->name('webhooks.mercadopago');
```

Listen for the outcome in your own app — the package never mutates
anything beyond its own `PaymentIntent`:

```php
use ForgeStack\IdempotentPayments\Events\PaymentCaptured;
use ForgeStack\IdempotentPayments\Events\PaymentFailed;

// app/Listeners/AdvanceOrderOnPaymentCaptured.php — auto-discovered by
// Laravel's event auto-discovery, no Event::listen() needed.
final class AdvanceOrderOnPaymentCaptured
{
    public function handle(PaymentCaptured $event): void
    {
        Order::where('payment_intent_id', $event->paymentIntent->id)
            ->update(['status' => 'paid']);
    }
}
```

## Gateways

| Gateway | Class | Flow |
| ------- | ----- | ---- |
| MercadoPago | `MercadoPagoGateway` | Checkout Preferences — hosted redirect. HMAC `X-Signature` webhook verification. |
| OpenPay (BBVA México) | `OpenPayGateway` | Hosted Payment Page — redirect. HTTP Basic Auth webhook verification. |
| Free / zero-amount | `FreeGateway` | Synchronous capture, no checkout screen, no external call. |
| Fake | `FakeGateway` | Demo/tests — never calls a real provider, always approves on webhook. |

All four implement the same three-method `PaymentGatewayInterface`
(`createIntent`, `handleWebhook`, `validateWebhook`) — write your own driver
for another gateway without touching `CreatePaymentIntent` or
`ProcessPaymentWebhook`.

Neither real gateway knows a Laravel route by name: redirect/notification
URLs are passed in as plain strings through the constructor. `FakeGateway`
takes an optional `fn(string $reference): ?string` closure instead, for
apps that want its checkout URL to point at their own confirmation page.

## Events

`PaymentCaptured`, `PaymentFailed` — listen for these in your own app to
react to state changes (update your own `Order`/`Booking` row, notify a
user, reconcile a ledger, etc.).

## Testing

```bash
composer test
```

Runs against Orchestra Testbench with an in-memory SQLite database —
`FakeGateway` and `Http::fake()` cover the full flow without a real
MercadoPago/OpenPay sandbox account.
