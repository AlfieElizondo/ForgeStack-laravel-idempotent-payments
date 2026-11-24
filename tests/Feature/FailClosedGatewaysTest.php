<?php

declare(strict_types=1);

namespace ForgeStack\IdempotentPayments\Tests\Feature;

use ForgeStack\IdempotentPayments\Drivers\Gateways\FakeGateway;
use ForgeStack\IdempotentPayments\Drivers\Gateways\MercadoPagoGateway;
use ForgeStack\IdempotentPayments\Drivers\Gateways\OpenPayGateway;
use ForgeStack\IdempotentPayments\Enums\PaymentStatus;
use ForgeStack\IdempotentPayments\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * P-02, P-03 y P-04: las pasarelas fallan cerrado.
 *  - Un secreto de webhook vacío rechaza; aceptar sin firma exige un opt-in explícito.
 *  - FakeGateway no acepta ni valida webhooks fuera de local/testing.
 *  - Un id de webhook con formato inesperado no produce ninguna petición HTTP.
 */
class FailClosedGatewaysTest extends TestCase
{
    private function mpRequest(string $dataId, string $secret, string $requestId = 'req-1', string $ts = '1700000000'): Request
    {
        $manifest = "id:{$dataId};request-id:{$requestId};ts:{$ts};";
        $v1 = hash_hmac('sha256', $manifest, $secret);

        $request = Request::create('/webhook', 'POST', ['data' => ['id' => $dataId]]);
        $request->headers->set('x-signature', "ts={$ts},v1={$v1}");
        $request->headers->set('x-request-id', $requestId);

        return $request;
    }

    private function basicRequest(?string $user, ?string $pass): Request
    {
        $request = Request::create('/webhook', 'POST');
        if ($user !== null) {
            $request->headers->set('PHP_AUTH_USER', $user);
            $request->headers->set('PHP_AUTH_PW', (string) $pass);
            $request->server->set('PHP_AUTH_USER', $user);
            $request->server->set('PHP_AUTH_PW', (string) $pass);
        }

        return $request;
    }

    // ---------- P-02 · Mercado Pago ----------

    public function test_mercadopago_rejects_webhooks_when_the_secret_is_empty(): void
    {
        $gateway = new MercadoPagoGateway(accessToken: 'tok', webhookSecret: '');

        $this->assertFalse($gateway->validateWebhook($this->mpRequest('123', '')));
        $this->assertFalse($gateway->validateWebhook(Request::create('/webhook', 'POST')));
    }

    public function test_mercadopago_accepts_unsigned_webhooks_only_with_the_explicit_opt_in(): void
    {
        $gateway = new MercadoPagoGateway(accessToken: 'tok', webhookSecret: '', allowUnsignedWebhooks: true);

        $this->assertTrue($gateway->validateWebhook(Request::create('/webhook', 'POST')));
    }

    public function test_mercadopago_still_verifies_a_valid_signature_and_rejects_a_bad_one(): void
    {
        $gateway = new MercadoPagoGateway(accessToken: 'tok', webhookSecret: 's3cret');

        $this->assertTrue($gateway->validateWebhook($this->mpRequest('123', 's3cret')));
        $this->assertFalse($gateway->validateWebhook($this->mpRequest('123', 'otro-secreto')));
    }

    // ---------- P-12 · data.id viene en la URL ----------

    private function mpQueryRequest(string $query, array $body, string $signedId, string $secret, string $ts = '1700000000'): Request
    {
        $v1 = hash_hmac('sha256', "id:{$signedId};request-id:req-1;ts:{$ts};", $secret);
        $request = Request::create('/webhook?'.$query, 'POST', $body);
        $request->server->set('QUERY_STRING', $query);
        $request->headers->set('x-signature', "ts={$ts},v1={$v1}");
        $request->headers->set('x-request-id', 'req-1');

        return $request;
    }

    public function test_mercadopago_signs_the_data_id_from_the_query_string_when_the_body_has_none(): void
    {
        // Aviso real de orden comercial (2026-11-22): el cuerpo no trae data.id; solo la URL.
        $gateway = new MercadoPagoGateway(accessToken: 'tok', webhookSecret: 's3cret');
        $request = $this->mpQueryRequest('data.id=46345223683&type=topic_merchant_order_wh', ['data' => ['status' => 'opened']], '46345223683', 's3cret');

        $this->assertTrue($gateway->validateWebhook($request));
    }

    public function test_mercadopago_prefers_the_query_string_id_over_the_body(): void
    {
        $gateway = new MercadoPagoGateway(accessToken: 'tok', webhookSecret: 's3cret');
        $request = $this->mpQueryRequest('data.id=111&type=payment', ['data' => ['id' => '999']], '111', 's3cret');

        $this->assertTrue($gateway->validateWebhook($request));
    }

    public function test_mercadopago_lowercases_an_alphanumeric_data_id_before_signing(): void
    {
        $gateway = new MercadoPagoGateway(accessToken: 'tok', webhookSecret: 's3cret');
        $request = $this->mpQueryRequest('data.id=ABC123&type=payment', [], 'abc123', 's3cret');

        $this->assertTrue($gateway->validateWebhook($request));
    }

    // ---------- P-07 · tolerancia de tiempo ----------

    public function test_mercadopago_rejects_an_old_signature_when_a_tolerance_is_configured(): void
    {
        $gateway = new MercadoPagoGateway(accessToken: 'tok', webhookSecret: 's3cret', signatureToleranceSeconds: 600);

        $this->assertFalse($gateway->validateWebhook($this->mpRequest('123', 's3cret', ts: (string) (time() - 3600))));
        $this->assertTrue($gateway->validateWebhook($this->mpRequest('123', 's3cret', ts: (string) time())));
    }

    public function test_mercadopago_accepts_the_timestamp_in_seconds_or_milliseconds(): void
    {
        $gateway = new MercadoPagoGateway(accessToken: 'tok', webhookSecret: 's3cret', signatureToleranceSeconds: 600);

        $this->assertTrue($gateway->validateWebhook($this->mpRequest('123', 's3cret', ts: (string) (time() * 1000))));
        $this->assertFalse($gateway->validateWebhook($this->mpRequest('123', 's3cret', ts: (string) ((time() - 3600) * 1000))));
    }

    public function test_mercadopago_without_a_tolerance_keeps_the_previous_behaviour(): void
    {
        // Sin el parámetro (Europharma) no cambia nada: la idempotencia ya neutraliza repeticiones.
        $gateway = new MercadoPagoGateway(accessToken: 'tok', webhookSecret: 's3cret');

        $this->assertTrue($gateway->validateWebhook($this->mpRequest('123', 's3cret', ts: '1700000000')));
    }

    // ---------- P-02 · Openpay ----------

    public function test_openpay_rejects_webhooks_when_credentials_are_empty(): void
    {
        $gateway = new OpenPayGateway(merchantId: 'm', privateKey: 'k');

        $this->assertFalse($gateway->validateWebhook($this->basicRequest(null, null)));
        $this->assertFalse($gateway->validateWebhook($this->basicRequest('', '')));
    }

    public function test_openpay_accepts_unsigned_webhooks_only_with_the_explicit_opt_in(): void
    {
        $gateway = new OpenPayGateway(merchantId: 'm', privateKey: 'k', allowUnsignedWebhooks: true);

        $this->assertTrue($gateway->validateWebhook($this->basicRequest(null, null)));
    }

    public function test_openpay_still_checks_basic_credentials_when_configured(): void
    {
        $gateway = new OpenPayGateway(merchantId: 'm', privateKey: 'k', webhookUser: 'u', webhookPassword: 'p');

        $this->assertTrue($gateway->validateWebhook($this->basicRequest('u', 'p')));
        $this->assertFalse($gateway->validateWebhook($this->basicRequest('u', 'mala')));
        $this->assertFalse($gateway->validateWebhook($this->basicRequest(null, null)));
    }

    // ---------- P-03 · FakeGateway ----------

    public function test_fake_gateway_does_not_validate_or_process_webhooks_outside_local_and_testing(): void
    {
        $this->app['env'] = 'production';
        $gateway = new FakeGateway();

        try {
            $this->assertFalse($gateway->validateWebhook(Request::create('/webhook', 'POST')));

            $this->expectException(\RuntimeException::class);
            $gateway->handleWebhook(['reference' => 'FAKE-1']);
        } finally {
            $this->app['env'] = 'testing';   // el rollback de migraciones no debe correr "en producción"
        }
    }

    public function test_fake_gateway_still_works_in_testing(): void
    {
        $gateway = new FakeGateway();

        $this->assertTrue($gateway->validateWebhook(Request::create('/webhook', 'POST')));
        $this->assertSame(PaymentStatus::Captured, $gateway->handleWebhook(['reference' => 'FAKE-1'])->status);
    }

    // ---------- P-04 · id del webhook ----------

    /** @return array<string, array{0: string}> */
    public static function badIds(): array
    {
        return [
            'recorre rutas' => ['../x'],
            'con barra' => ['123/refunds'],
            'con consulta' => ['123?access_token=x'],
            'con espacios' => ['12 3'],
            'vacío tras recortar' => ['  '],
        ];
    }

    #[DataProvider('badIds')]
    public function test_mercadopago_ignores_a_malformed_payment_id_without_any_http_call(string $id): void
    {
        Http::fake();
        $gateway = new MercadoPagoGateway(accessToken: 'tok', webhookSecret: 's');

        $response = $gateway->handleWebhook(['data' => ['id' => $id]]);

        Http::assertNothingSent();
        $this->assertSame(PaymentStatus::Pending, $response->status);
        $this->assertSame('', $response->reference);
    }

    #[DataProvider('badIds')]
    public function test_openpay_ignores_a_malformed_charge_id_without_any_http_call(string $id): void
    {
        Http::fake();
        $gateway = new OpenPayGateway(merchantId: 'm', privateKey: 'k');

        $response = $gateway->handleWebhook(['transaction' => ['id' => $id]]);

        Http::assertNothingSent();
        $this->assertSame(PaymentStatus::Pending, $response->status);
        $this->assertSame('', $response->reference);
    }

    public function test_mercadopago_still_looks_up_a_well_formed_numeric_id(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments/123456' => Http::response(['status' => 'approved'], 200)]);
        $gateway = new MercadoPagoGateway(accessToken: 'tok', webhookSecret: 's');

        $response = $gateway->handleWebhook(['data' => ['id' => 123456]]);

        $this->assertSame(PaymentStatus::Captured, $response->status);
        $this->assertSame('123456', $response->reference);
    }

    public function test_openpay_still_looks_up_a_well_formed_charge_id(): void
    {
        Http::fake(['sandbox-api.openpay.mx/*' => Http::response(['status' => 'completed'], 200)]);
        $gateway = new OpenPayGateway(merchantId: 'm', privateKey: 'k');

        $response = $gateway->handleWebhook(['transaction' => ['id' => 'trxabc123_-X']]);

        $this->assertSame(PaymentStatus::Captured, $response->status);
    }
}
