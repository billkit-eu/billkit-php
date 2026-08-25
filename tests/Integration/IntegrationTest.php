<?php

declare(strict_types=1);

namespace BillKit\Tests\Integration;

use BillKit\BillKitClient;
use BillKit\Exception\AuthenticationException;
use BillKit\Exception\ConflictException;
use BillKit\Exception\InvalidRequestException;
use BillKit\Exception\PermissionException;
use BillKit\Exception\ResourceMissingException;
use BillKit\Exception\WebhookVerificationException;
use BillKit\Webhooks;
use PHPUnit\Framework\TestCase;

/**
 * PHP SDK integration suite, run against a **live** BillKit API.
 *
 * Skipped unless `BILLKIT_INTEGRATION_BASE_URL` is set; boot a stack with
 * `make sdk-integration` (see `sdk/integration/SCENARIOS.md`).
 *
 * Every test is tagged with a scenario id from
 * `sdk/integration/scenarios.json`, and `testZzManifestCoverage` asserts
 * this suite covers **all** of them. That assertion is what makes the
 * parity matrix real: adding a scenario to the manifest fails this suite
 * until php implements it, and the node / python suites carry the
 * identical check.
 *
 * The unit suites (`tests/*Test.php`) already cover transport, retry, and
 * error-mapping mechanics against a mock PSR-18 client. This suite
 * deliberately does *not* re-test those in isolation. It proves the SDK
 * drives the real wire contract: real cursor pagination, real idempotency
 * records, and the real money path through the fake Mollie provider.
 */
final class IntegrationTest extends TestCase
{
    /** Scenario ids this class claims; checked against the shared manifest. */
    private const COVERED = [
        'auth.valid_key',
        'auth.bad_key',
        'auth.scoped_key_denied',
        'crud.product',
        'crud.price',
        'crud.customer',
        'crud.coupon',
        'crud.tax_rate',
        'crud.webhook_endpoint',
        'pagination.has_more',
        'pagination.auto_iter',
        'idempotency.replay',
        'idempotency.key_reuse_conflict',
        'errors.not_found',
        'errors.invalid_request',
        'money.checkout_to_active',
        'money.partial_refund',
        'money.over_refund_rejected',
        'money.dispute_opened',
        'webhooks.verify_roundtrip',
        'webhooks.reject_tampered',
        'webhooks.reject_stale',
    ];

    /** Only scenarios in this family are required of the php SDK. */
    private const FAMILY = 'server';

    private const SECRET = 'whsec_integration_secret';

    /** @var array{api_key: string, tenant_id: string, mollie_route_id: string, session_token: string}|null */
    private static ?array $tenant = null;

    protected function setUp(): void
    {
        if (! IntegrationHarness::enabled()) {
            self::markTestSkipped('Set BILLKIT_INTEGRATION_BASE_URL to run the SDK integration suite.');
        }
    }

    /** @return array{api_key: string, tenant_id: string, mollie_route_id: string, session_token: string} */
    private function tenant(): array
    {
        // Provisioned once per class so the money specs share a tenant and
        // the suite doesn't pay for a tenant per test.
        if (self::$tenant === null) {
            self::$tenant = IntegrationHarness::provisionTenant();
        }

        return self::$tenant;
    }

    private function client(?string $apiKey = null): BillKitClient
    {
        return new BillKitClient(
            apiKey: $apiKey ?? $this->tenant()['api_key'],
            baseUrl: IntegrationHarness::baseUrl(),
        );
    }

    /**
     * A product + price pair the money specs charge against.
     *
     * @return array{product: array<string, mixed>, price: array<string, mixed>}
     */
    private function makePlan(BillKitClient $c, int $amountCents = 2500): array
    {
        $product = $c->products->create(['name' => 'Plan ' . IntegrationHarness::idemKey()]);
        $price = $c->prices->create([
            'product_id' => $product['id'],
            'amount_cents' => $amountCents,
            'currency' => 'EUR',
            'interval' => 'month',
        ]);

        return ['product' => $product, 'price' => $price];
    }

    /**
     * Take a checkout session all the way to an active subscription.
     *
     * Order matters and mirrors production: settle the payment at the
     * provider *first*, then deliver the webhook. The API re-fetches payment
     * state from the provider rather than trusting the webhook body, so a
     * webhook delivered before the settle would correctly observe `open` and
     * do nothing.
     *
     * @return array{session: array<string, mixed>, provider_payment_id: string}
     */
    private function checkoutToActive(BillKitClient $c, string $priceId): array
    {
        $session = $c->checkoutSessions->create([
            'price_id' => $priceId,
            'customer_email' => 'buyer-' . IntegrationHarness::idemKey() . '@sdk-it.example.com',
            'success_url' => 'https://merchant.example.com/ok',
            'cancel_url' => 'https://merchant.example.com/cancel',
        ]);
        $providerPaymentId = IntegrationHarness::paymentIdFromCheckoutUrl((string) $session['url']);
        IntegrationHarness::settle($providerPaymentId, 'paid');
        IntegrationHarness::deliverMollieWebhook($this->tenant()['mollie_route_id'], $providerPaymentId);

        return ['session' => $session, 'provider_payment_id' => $providerPaymentId];
    }

    /** @return array<string, mixed> */
    private function findSubscription(BillKitClient $c, string $priceId): array
    {
        foreach ($c->subscriptions->all(['limit' => 100])['data'] as $row) {
            if ($row['price_id'] === $priceId) {
                return $row;
            }
        }
        self::fail("no subscription found for price {$priceId}");
    }

    /** @return array<string, mixed> */
    private function findPayment(BillKitClient $c, string $subscriptionId): array
    {
        foreach ($c->payments->all(['limit' => 100])['data'] as $row) {
            if ($row['subscription_id'] === $subscriptionId) {
                return $row;
            }
        }
        self::fail("no payment found for subscription {$subscriptionId}");
    }

    // ── auth ─────────────────────────────────────────────────────────

    public function testAuthValidKey(): void
    {
        $page = $this->client()->products->all();
        self::assertSame('list', $page['object']);
        self::assertIsArray($page['data']);
    }

    public function testAuthBadKey(): void
    {
        $this->expectException(AuthenticationException::class);
        $this->client('sk_test_0000000000000000000000')->products->all();
    }

    public function testAuthScopedKeyDenied(): void
    {
        $secret = IntegrationHarness::mintScopedKey($this->tenant()['api_key'], ['products:read']);
        $scoped = $this->client($secret);
        self::assertSame('list', $scoped->products->all()['object']);

        $this->expectException(PermissionException::class);
        $scoped->customers->all();
    }

    // ── crud ─────────────────────────────────────────────────────────

    public function testCrudProduct(): void
    {
        $c = $this->client();
        $created = $c->products->create([
            'name' => 'Round Trip',
            'description' => 'created by the php integration suite',
        ]);
        self::assertStringStartsWith('prod_', (string) $created['id']);

        self::assertSame('Round Trip', $c->products->retrieve((string) $created['id'])['name']);
        self::assertSame(
            'Round Trip v2',
            $c->products->update((string) $created['id'], ['name' => 'Round Trip v2'])['name'],
        );
        self::assertFalse($c->products->delete((string) $created['id'])['active']);
    }

    public function testCrudPrice(): void
    {
        $c = $this->client();
        ['product' => $product, 'price' => $price] = $this->makePlan($c, 1234);
        self::assertSame(1234, $price['amount_cents']);
        self::assertSame(
            $product['id'],
            $c->prices->retrieve((string) $price['id'])['product_id'],
        );

        $filtered = $c->prices->all(['product_id' => $product['id']]);
        self::assertContains($price['id'], array_column($filtered['data'], 'id'));
    }

    public function testCrudCustomer(): void
    {
        $c = $this->client();
        $email = 'cust-' . IntegrationHarness::idemKey() . '@sdk-it.example.com';
        $created = $c->customers->create(['email' => $email, 'name' => 'Ada Lovelace']);
        self::assertSame($email, $created['email']);
        self::assertSame(
            'Ada L.',
            $c->customers->update((string) $created['id'], ['name' => 'Ada L.'])['name'],
        );

        $c->customers->delete((string) $created['id']);
        $page = $c->customers->all(['limit' => 100]);
        self::assertNotContains($created['id'], array_column($page['data'], 'id'));
    }

    public function testCrudCoupon(): void
    {
        $c = $this->client();
        $code = 'SAVE' . substr((string) time(), -8);
        $created = $c->coupons->create([
            'code' => $code,
            'discount_type' => 'percent',
            'discount_value' => 25,
            'duration' => 'once',
        ]);
        self::assertSame($code, $created['code']);
        self::assertTrue($c->coupons->validate(['code' => $code])['valid']);

        $c->coupons->update((string) $created['id'], ['max_redemptions' => 5]);
        $c->coupons->delete((string) $created['id']);

        // A deleted coupon must stop validating, otherwise a revoked
        // discount would keep applying at checkout.
        self::assertFalse($c->coupons->validate(['code' => $code])['valid']);
    }

    public function testCrudTaxRate(): void
    {
        $c = $this->client();
        $created = $c->taxRates->create([
            'country_code' => 'NL',
            'rate_basis_points' => 2100,
            'display_name' => 'NL VAT',
        ]);
        self::assertSame(2100, $created['rate_basis_points']);
        self::assertSame(
            900,
            $c->taxRates->update((string) $created['id'], ['rate_basis_points' => 900])['rate_basis_points'],
        );
        $c->taxRates->delete((string) $created['id']);
    }

    public function testCrudWebhookEndpoint(): void
    {
        $c = $this->client();
        $created = $c->webhookEndpoints->create([
            'url' => 'https://merchant.example.com/hooks/billkit',
            'enabled_events' => ['*'],
            'description' => 'php integration suite',
        ]);
        // The signing secret is returned exactly once, on create.
        self::assertStringStartsWith('whsec_', (string) $created['secret']);

        $c->webhookEndpoints->update((string) $created['id'], ['description' => 'renamed']);

        $rotated = $c->webhookEndpoints->rotateSecret((string) $created['id']);
        self::assertStringStartsWith('whsec_', (string) $rotated['secret']);
        self::assertNotSame($created['secret'], $rotated['secret']);

        $c->webhookEndpoints->delete((string) $created['id']);
    }

    // ── pagination ───────────────────────────────────────────────────

    public function testPaginationHasMore(): void
    {
        $t = IntegrationHarness::provisionTenant('page');
        $c = $this->client($t['api_key']);
        for ($i = 0; $i < 5; $i++) {
            $c->products->create(['name' => "Paged {$i}"]);
        }

        $page = $c->products->all(['limit' => 2]);
        self::assertCount(2, $page['data']);
        self::assertTrue($page['has_more']);
    }

    public function testPaginationAutoIter(): void
    {
        // A dedicated tenant so the expected set is exactly what we created.
        $t = IntegrationHarness::provisionTenant('iter');
        $c = $this->client($t['api_key']);
        $expected = [];
        for ($i = 0; $i < 7; $i++) {
            $expected[] = $c->products->create(['name' => "Iter {$i}"])['id'];
        }

        $seen = [];
        foreach ($c->products->autoPagingIterator(2) as $product) {
            $seen[] = $product['id'];
        }

        // Exactly-once is the real assertion: a cursor that mis-orders ties
        // shows up here as a duplicate or a dropped row, not as a crash.
        self::assertCount(count($expected), $seen);
        sort($expected);
        sort($seen);
        self::assertSame($expected, $seen);
    }

    // ── idempotency ──────────────────────────────────────────────────

    public function testIdempotencyReplay(): void
    {
        $c = $this->client();
        $key = IntegrationHarness::idemKey();
        $first = $c->products->create(['name' => 'Idempotent Product', 'idempotency_key' => $key]);
        $second = $c->products->create(['name' => 'Idempotent Product', 'idempotency_key' => $key]);
        self::assertSame($first['id'], $second['id']);
    }

    public function testIdempotencyKeyReuseConflict(): void
    {
        $c = $this->client();
        $key = IntegrationHarness::idemKey();
        $c->products->create(['name' => 'First Body', 'idempotency_key' => $key]);

        $this->expectException(ConflictException::class);
        $c->products->create(['name' => 'Different Body', 'idempotency_key' => $key]);
    }

    // ── errors ───────────────────────────────────────────────────────

    public function testErrorsNotFound(): void
    {
        $this->expectException(ResourceMissingException::class);
        $this->client()->products->retrieve('prod_does_not_exist');
    }

    public function testErrorsInvalidRequest(): void
    {
        // A 4-char currency fails schema validation. Asserting on `param` is
        // the point: it proves the envelope's field-level detail survives the
        // wire round-trip into the typed exception, which is what lets a
        // caller highlight the offending input rather than show a generic
        // error.
        try {
            $this->client()->prices->create([
                'product_id' => 'prod_whatever',
                'amount_cents' => 100,
                'currency' => 'EURO',
                'interval' => 'month',
            ]);
            self::fail('expected InvalidRequestException');
        } catch (InvalidRequestException $e) {
            self::assertSame('currency', $e->param);
            self::assertSame('parameter_invalid', $e->errorCode);
        }
    }

    // ── money ────────────────────────────────────────────────────────

    public function testMoneyCheckoutToActive(): void
    {
        $c = $this->client();
        $price = $this->makePlan($c, 4200)['price'];
        $this->checkoutToActive($c, (string) $price['id']);

        $sub = $this->findSubscription($c, (string) $price['id']);
        self::assertSame('active', $sub['status']);

        $paid = $this->findPayment($c, (string) $sub['id']);
        self::assertSame('paid', $paid['status']);
        self::assertSame(4200, $paid['amount_cents']);
    }

    public function testMoneyPartialRefund(): void
    {
        $c = $this->client();
        $price = $this->makePlan($c, 10000)['price'];
        $this->checkoutToActive($c, (string) $price['id']);
        $sub = $this->findSubscription($c, (string) $price['id']);
        $payment = $this->findPayment($c, (string) $sub['id']);

        $refund = $c->refunds->create([
            'payment_id' => $payment['id'],
            'amount_cents' => 3000,
            'reason' => 'integration partial',
        ]);
        self::assertSame(3000, $refund['amount_cents']);

        $after = $c->payments->retrieve((string) $payment['id']);
        self::assertSame(3000, $after['amount_refunded_cents']);
        self::assertSame(7000, $after['amount_refundable_cents']);
    }

    public function testMoneyOverRefundRejected(): void
    {
        $c = $this->client();
        $price = $this->makePlan($c, 5000)['price'];
        $this->checkoutToActive($c, (string) $price['id']);
        $sub = $this->findSubscription($c, (string) $price['id']);
        $payment = $this->findPayment($c, (string) $sub['id']);

        // The guard that stops BillKit paying out more than it took.
        $this->expectException(InvalidRequestException::class);
        $c->refunds->create(['payment_id' => $payment['id'], 'amount_cents' => 5001]);
    }

    public function testMoneyDisputeOpened(): void
    {
        $c = $this->client();
        $price = $this->makePlan($c, 7700)['price'];
        $result = $this->checkoutToActive($c, (string) $price['id']);

        // Open the chargeback at the provider, then re-deliver the payment
        // webhook. The reconciler picks the transition up on that hop.
        IntegrationHarness::chargeback($result['provider_payment_id'], '77.00', 'fraudulent');
        IntegrationHarness::deliverMollieWebhook(
            $this->tenant()['mollie_route_id'],
            $result['provider_payment_id'],
        );

        $disputes = $c->disputes->all(['limit' => 100]);
        $match = array_values(array_filter(
            $disputes['data'],
            static fn (array $d): bool => $d['amount_cents'] === 7700,
        ));
        self::assertNotEmpty($match, 'a dispute should exist for the charged-back payment');
        self::assertSame('open', $match[0]['status']);
        self::assertSame(
            $match[0]['id'],
            $c->disputes->retrieve((string) $match[0]['id'])['id'],
        );
    }

    // ── webhooks ─────────────────────────────────────────────────────

    /** Sign the documented wire format: `"{t}." + rawBody`, HMAC-SHA256, hex. */
    private function sign(string $body, int $ts): string
    {
        return 't=' . $ts . ',v1=' . hash_hmac('sha256', $ts . '.' . $body, self::SECRET);
    }

    private function body(): string
    {
        return json_encode(['id' => 'evt_1', 'type' => 'subscription.created'], JSON_THROW_ON_ERROR);
    }

    public function testWebhooksVerifyRoundtrip(): void
    {
        $body = $this->body();
        $event = Webhooks::verifySignature($body, $this->sign($body, time()), self::SECRET);
        self::assertSame('evt_1', $event['id']);
    }

    public function testWebhooksRejectTampered(): void
    {
        $body = $this->body();
        $header = $this->sign($body, time());

        $this->expectException(WebhookVerificationException::class);
        Webhooks::verifySignature(str_replace('evt_1', 'evt_2', $body), $header, self::SECRET);
    }

    public function testWebhooksRejectStale(): void
    {
        $body = $this->body();
        $stale = time() - 10000;

        $this->expectException(WebhookVerificationException::class);
        Webhooks::verifySignature($body, $this->sign($body, $stale), self::SECRET);
    }

    // ── parity gate ──────────────────────────────────────────────────

    public function testZzManifestCoverage(): void
    {
        $path = __DIR__ . '/../../integration/scenarios.json';
        /** @var array{scenarios: list<array{id: string, family: string}>} $manifest */
        $manifest = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $required = array_column(
            array_filter(
                $manifest['scenarios'],
                static fn (array $s): bool => $s['family'] === self::FAMILY,
            ),
            'id',
        );

        $missing = array_values(array_diff($required, self::COVERED));
        $unknown = array_values(array_diff(self::COVERED, $required));

        self::assertSame(
            [],
            $missing,
            'Not implemented by the php suite: ' . implode(', ', $missing)
            . '. Implement them, or drop them from sdk/integration/scenarios.json if the '
            . 'capability is genuinely gone from every SDK.',
        );
        self::assertSame(
            [],
            $unknown,
            'Claimed ids that are not in the manifest: ' . implode(', ', $unknown)
            . '. Add them to sdk/integration/scenarios.json so node + python are held to '
            . 'the same bar (that is the whole point of the manifest).',
        );
    }
}
