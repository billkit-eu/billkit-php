<?php

declare(strict_types=1);

namespace BillKit\Tests\Integration;

use BillKit\BillKitClient;
use BillKit\Exception\AuthenticationException;
use BillKit\Exception\ConflictException;
use BillKit\Exception\InvalidRequestException;
use BillKit\Exception\PermissionException;
use BillKit\Exception\ResourceMissingException;
use BillKit\Exception\ServerException;
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
        'crud.price_archive',
        'crud.customer',
        'crud.coupon',
        'crud.tax_rate',
        'crud.webhook_endpoint',
        'crud.price_decimal_rate',
        'crud.price_tiered',
        'crud.credit_note_absent_until_refunded',
        'filters.subscription_renewal_state',
        'filters.customer_provisional',
        'pagination.has_more',
        'pagination.auto_iter',
        'idempotency.replay',
        'idempotency.key_reuse_conflict',
        'idempotency.in_progress_converges',
        'errors.not_found',
        'errors.invalid_request',
        'errors.status_drives_class',
        'money.checkout_to_active',
        'money.partial_refund',
        'money.over_refund_rejected',
        'money.dispute_opened',
        'money.credit_note_for_refund',
        'money.void_refused_on_paid_invoice',
        'usage.record_and_replay',
        'usage.list_reconciliation',
        'usage.non_metered_rejected',
        'usage.dedupe_identifier',
        'usage.summary_forecast',
        'webhooks.verify_roundtrip',
        'webhooks.reject_tampered',
        'webhooks.reject_stale',
    ];

    /** Only scenarios in this family are required of the php SDK. */
    private const FAMILY = 'server';

    private const SECRET = 'bkwhsec_integration_secret';

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
    private function makePlan(BillKitClient $c, int $amountCents = 2500, ?string $usageType = null): array
    {
        $product = $c->products->create(['name' => 'Plan ' . IntegrationHarness::idemKey()]);
        $price = $c->prices->create([
            'product_id' => $product['id'],
            'amount_cents' => $amountCents,
            'currency' => 'EUR',
            'interval' => 'month',
            'usage_type' => $usageType,
        ]);

        return ['product' => $product, 'price' => $price];
    }

    /**
     * Mint an ACTIVE subscription on `$priceId` and return it.
     *
     * Same machinery as the money specs: checkout -> settle at the fake
     * Mollie -> deliver the webhook, then find the subscription by price.
     *
     * @return array<string, mixed>
     */
    private function activeSubscription(BillKitClient $c, string $priceId): array
    {
        $this->checkoutToActive($c, $priceId);
        $sub = $this->findSubscription($c, $priceId);
        self::assertSame('active', $sub['status']);

        return $sub;
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
        $this->client('bk_test_0000000000000000000000')->products->all();
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
        // Archive is the update route: the product has no delete, because
        // an archived product stays readable.
        self::assertFalse(
            $c->products->update((string) $created['id'], ['active' => false])['active'],
        );
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

    public function testCrudPriceArchive(): void
    {
        $c = $this->client();
        ['product' => $product, 'price' => $price] = $this->makePlan($c, 777);

        $archived = $c->prices->update((string) $price['id'], ['active' => false]);
        self::assertSame($price['id'], $archived['id']);
        self::assertFalse($archived['active']);

        // Archiving is not a delete: the row survives, so a subscription
        // still pointing at it can be read back rather than dangling.
        self::assertFalse($c->prices->retrieve((string) $price['id'])['active']);
        $listed = $c->prices->all(['product_id' => $product['id']]);
        self::assertContains($price['id'], array_column($listed['data'], 'id'));

        // Re-archiving returns it unchanged instead of erroring, which is
        // what makes a retried archive safe.
        $again = $c->prices->update((string) $price['id'], ['active' => false]);
        self::assertSame($price['id'], $again['id']);
        self::assertFalse($again['active']);

        // `active` moves both ways, and the money-bearing fields survive
        // the round trip, which is the immutability claim that matters.
        $back = $c->prices->update((string) $price['id'], ['active' => true]);
        self::assertTrue($back['active']);
        self::assertSame(777, $back['amount_cents']);
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

        // The customer leaves the API, so the body is a marker, not a row.
        $deleted = $c->customers->delete((string) $created['id']);
        self::assertSame(
            ['id' => $created['id'], 'object' => 'customer', 'deleted' => true],
            $deleted,
        );

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
        $c->coupons->update((string) $created['id'], ['active' => false]);

        // A withdrawn coupon must stop validating, otherwise a retired
        // discount would keep applying at checkout...
        self::assertFalse($c->coupons->validate(['code' => $code])['valid']);
        // ...while staying readable, because a discount already applied to
        // a live subscription has to be traceable to the coupon behind it.
        self::assertFalse($c->coupons->retrieve((string) $created['id'])['active']);
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
        // Retiring is an update, and the rate stays readable: an invoice
        // records the percentage it charged, not the rate row.
        self::assertFalse(
            $c->taxRates->update((string) $created['id'], ['active' => false])['active'],
        );
        self::assertFalse($c->taxRates->retrieve((string) $created['id'])['active']);
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
        self::assertStringStartsWith('bkwhsec_', (string) $created['secret']);

        $c->webhookEndpoints->update((string) $created['id'], ['description' => 'renamed']);

        $rotated = $c->webhookEndpoints->rotateSecret((string) $created['id']);
        self::assertStringStartsWith('bkwhsec_', (string) $rotated['secret']);
        self::assertNotSame($created['secret'], $rotated['secret']);

        // Disabling stops delivery and keeps everything else, so the
        // endpoint is still listed and can be turned back on.
        $disabled = $c->webhookEndpoints->update((string) $created['id'], [
            'status' => 'disabled',
        ]);
        self::assertSame('disabled', $disabled['status']);
        $page = $c->webhookEndpoints->all(['limit' => 100]);
        self::assertContains($created['id'], array_column($page['data'], 'id'));

        // Deleting is the other act, and it is a real one: a URL
        // registered by mistake leaves the account rather than sitting
        // there disabled for good.
        $gone = $c->webhookEndpoints->delete((string) $created['id']);
        self::assertSame(
            ['id' => $created['id'], 'object' => 'webhook_endpoint', 'deleted' => true],
            $gone,
        );
        $after = $c->webhookEndpoints->all(['limit' => 100]);
        self::assertNotContains($created['id'], array_column($after['data'], 'id'));

        $this->expectException(ResourceMissingException::class);
        $c->webhookEndpoints->retrieve((string) $created['id']);
    }

    /**
     * A 12-dp rate round-trips byte-identical, as a string.
     *
     * The one scenario that can silently corrupt money: a PHP `float`
     * anywhere on the path rounds a per-call rate away, and the resulting
     * invoice is wrong by orders of magnitude rather than by a cent.
     */
    public function testCrudPriceDecimalRate(): void
    {
        $c = $this->client();
        $product = $c->products->create(['name' => 'Metered ' . IntegrationHarness::idemKey()]);
        $rate = '0.000000000001'; // twelve decimal places, in MINOR units
        $price = $c->prices->create([
            'product_id' => $product['id'],
            'currency' => 'EUR',
            'interval' => 'month',
            'usage_type' => 'metered',
            'unit_amount_decimal' => $rate,
        ]);
        self::assertIsString($price['unit_amount_decimal']);
        self::assertSame($rate, $price['unit_amount_decimal']);

        // The read path is a separate serializer, so assert it separately.
        $fetched = $c->prices->retrieve((string) $price['id']);
        self::assertIsString($fetched['unit_amount_decimal']);
        self::assertSame($rate, $fetched['unit_amount_decimal']);
    }

    public function testCrudPriceTiered(): void
    {
        $c = $this->client();
        $product = $c->products->create(['name' => 'Tiered ' . IntegrationHarness::idemKey()]);
        $price = $c->prices->create([
            'product_id' => $product['id'],
            'currency' => 'EUR',
            'interval' => 'month',
            'usage_type' => 'metered',
            'billing_scheme' => 'tiered',
            // Never defaulted: the same table under the two modes is a
            // different bill, not a rounding difference.
            'tiers_mode' => 'graduated',
            'tiers' => [
                ['up_to' => 1000, 'unit_amount_decimal' => '0.05'],
                ['up_to' => 'inf', 'unit_amount_decimal' => '0.0125'],
            ],
        ]);
        self::assertSame('tiered', $price['billing_scheme']);
        self::assertSame('graduated', $price['tiers_mode']);
        self::assertCount(2, $price['tiers']);
        foreach ($price['tiers'] as $tier) {
            self::assertIsString($tier['unit_amount_decimal']);
        }
        self::assertSame('0.05', $price['tiers'][0]['unit_amount_decimal']);
        self::assertSame('0.0125', $price['tiers'][1]['unit_amount_decimal']);
    }

    public function testCrudCreditNoteAbsentUntilRefunded(): void
    {
        $c = $this->client();
        // There is no `create` on the resource at all — issuance hangs off a
        // settled refund. Assert the read surface is reachable and honest
        // about having nothing yet.
        self::assertSame('list', $c->creditNotes->all(['limit' => 10])['object']);

        $this->expectException(ResourceMissingException::class);
        $c->creditNotes->retrieve('cn_does_not_exist');
    }

    // ── filters ──────────────────────────────────────────────────────

    public function testFiltersSubscriptionRenewalState(): void
    {
        $c = $this->client();
        $price = $this->makePlan($c, 1500)['price'];
        $sub = $this->activeSubscription($c, (string) $price['id']);

        $paused = $c->subscriptions->pause((string) $sub['id']);
        // The whole point: pausing lands in renewal_state and leaves status
        // alone, because the customer has paid for the period they are in.
        self::assertSame('paused', $paused['renewal_state']);
        self::assertSame('active', $paused['status']);

        $byRenewalState = $c->subscriptions->all(['renewal_state' => 'paused', 'limit' => 100]);
        self::assertContains($sub['id'], array_column($byRenewalState['data'], 'id'));

        // ...and it is still an `active` subscription to the status filter.
        $byStatus = $c->subscriptions->all(['status' => 'active', 'limit' => 100]);
        self::assertContains($sub['id'], array_column($byStatus['data'], 'id'));

        // `status=paused` is not a value the API accepts. It used to be, and
        // returned a confident, wrong, empty page; now it is refused so the
        // mistake is visible.
        try {
            $c->subscriptions->all(['status' => 'paused']);
            self::fail('status=paused should be rejected');
        } catch (InvalidRequestException $e) {
            self::assertSame('status', $e->param);
        }
    }

    /**
     * A checkout that captures an email commits its Customer *before* the
     * charge, so a checkout nobody finished leaves a row behind.
     * `provisional` is the only thing that tells the two apart, and a
     * fresh tenant is what makes the assertion exact.
     */
    public function testFiltersCustomerProvisional(): void
    {
        $t = IntegrationHarness::provisionTenant('provisional');
        $c = new BillKitClient($t['api_key'], IntegrationHarness::baseUrl());
        $created = $c->customers->create(['email' => 'buyer-' . IntegrationHarness::idemKey() . '@example.com']);

        $ids = static fn (array $params): array => array_column(
            $c->customers->all($params + ['limit' => 100])['data'],
            'id',
        );

        self::assertContains($created['id'], $ids(['provisional' => false]));
        self::assertNotContains($created['id'], $ids(['provisional' => true]));
        // Omitted means both kinds, which is why the filter has to be
        // reachable at all: the default answer is not the one a "list my
        // customers" screen wants.
        self::assertContains($created['id'], $ids([]));
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

    /**
     * The contract a caller depends on: firing the same keyed create from
     * several workers yields ONE resource and no exception.
     *
     * A request that arrives while another holder of the key is still
     * running gets `409 idempotency_in_progress` — the one 4xx the client
     * retries, because the charge may already have happened and the obvious
     * workaround (retry with a fresh key) is what turns one charge into two.
     *
     * PHP has no in-process concurrency, so the racers are raw curl handles
     * pumped until their request bodies are on the wire and only drained
     * afterwards; the SDK's own create runs in between. Whether it lands
     * inside the server's in-flight window is the server's timing to decide,
     * so this can pass without entering it; what it can never do is pass
     * while the client treats that 409 as terminal. The deterministic proof
     * is in `tests/RetryTest.php`.
     */
    public function testIdempotencyInProgressConverges(): void
    {
        $t = IntegrationHarness::provisionTenant('inflight');
        $c = new BillKitClient($t['api_key'], IntegrationHarness::baseUrl());
        $key = IntegrationHarness::idemKey();
        $name = 'Concurrent ' . $key;

        [$viaSdk, $racers] = IntegrationHarness::raceJson(
            '/v1/products',
            ['name' => $name],
            ['Authorization' => 'Bearer ' . $t['api_key'], 'Idempotency-Key' => $key],
            6,
            static fn (): array => $c->products->create(['name' => $name, 'idempotency_key' => $key]),
        );

        /** @var array{id: string} $viaSdk */
        foreach ($racers as [$status, $body]) {
            self::assertSame(200, $status, "racer answered {$status}: {$body}");
            /** @var array{id: string} $decoded */
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($viaSdk['id'], $decoded['id'], 'every attempt must resolve to the same product');
        }

        // And the server really did create only one row.
        $rows = array_filter(
            $c->products->all(['limit' => 100])['data'],
            static fn (array $row): bool => $row['name'] === $name,
        );
        self::assertCount(1, $rows);
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

    /**
     * Not a contrived body: every request that never reaches a route handler
     * is serialised by the API's framework-level handler as
     * `{"type": "api_error", "code": "unhandled"}` with the original 4xx
     * status. Mapping on `type` made a plain 404 — a typo'd id, an SDK/API
     * version skew — arrive as `ServerException`, which is the class retry
     * and alerting policies key on.
     *
     * Driven through the transport rather than a resource because that is
     * what a version skew looks like: the SDK asking for a route this API
     * does not have.
     */
    public function testErrorsStatusDrivesClass(): void
    {
        try {
            $this->client()->transport->request('GET', '/v1/no_such_resource');
            self::fail('expected ResourceMissingException');
        } catch (ResourceMissingException $e) {
            self::assertNotInstanceOf(ServerException::class, $e);
            // The envelope value is still carried verbatim; it just does not
            // choose the class.
            self::assertSame('api_error', $e->errorType);
            self::assertSame(404, $e->statusCode);
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

    public function testMoneyCreditNoteForRefund(): void
    {
        $c = $this->client();
        $price = $this->makePlan($c, 6400)['price'];
        $result = $this->checkoutToActive($c, (string) $price['id']);
        $sub = $this->findSubscription($c, (string) $price['id']);
        $payment = $this->findPayment($c, (string) $sub['id']);

        $invoice = null;
        foreach ($c->invoices->all(['limit' => 100])['data'] as $row) {
            if ($row['payment_id'] === $payment['id']) {
                $invoice = $row;
                break;
            }
        }
        self::assertNotNull($invoice, 'the settled charge should have produced an invoice');

        $refund = $c->refunds->create([
            'payment_id' => $payment['id'],
            'amount_cents' => 6400,
        ]);
        // Nothing yet: the refund is pending and may still fail, and a
        // gapless series cannot un-issue a number.
        self::assertSame('pending', $refund['status']);
        self::assertSame([], $c->creditNotes->all(['invoice_id' => $invoice['id']])['data']);

        IntegrationHarness::settleRefundsFor($result['provider_payment_id'], 'refunded');
        IntegrationHarness::deliverMollieWebhook(
            $this->tenant()['mollie_route_id'],
            $result['provider_payment_id'],
        );

        $notes = $c->creditNotes->all(['invoice_id' => $invoice['id']])['data'];
        self::assertCount(1, $notes);
        $note = $notes[0];
        self::assertSame($invoice['id'], $note['invoice_id']);
        // Its own series, deliberately distinct from the invoice's: a tax
        // authority reads the two as different document classes.
        self::assertStringStartsWith('CN-', (string) $note['number']);
        self::assertNotSame($invoice['number'], $note['number']);
        // The identity the whole document rests on.
        self::assertSame($note['total_cents'], $note['subtotal_cents'] + $note['tax_cents']);
        self::assertSame(6400, $note['total_cents']);

        $fetched = $c->creditNotes->retrieve((string) $note['id']);
        self::assertSame($note['id'], $fetched['id']);
        self::assertSame('credit_note', $fetched['object']);
    }

    public function testMoneyVoidRefusedOnPaidInvoice(): void
    {
        $c = $this->client();
        $price = $this->makePlan($c, 1900)['price'];
        $this->checkoutToActive($c, (string) $price['id']);
        $sub = $this->findSubscription($c, (string) $price['id']);

        $invoice = null;
        foreach ($c->invoices->all(['limit' => 100])['data'] as $row) {
            if ($row['subscription_id'] === $sub['id']) {
                $invoice = $row;
                break;
            }
        }
        self::assertNotNull($invoice);
        self::assertSame('paid', $invoice['status']);

        // Not a limitation — the contract. Voiding claims the sale was never
        // owed, which is false once the money moved; the reversal there is a
        // credit note.
        try {
            $c->invoices->void((string) $invoice['id']);
            self::fail('voiding a paid invoice should have been refused');
        } catch (ConflictException $err) {
            self::assertSame('invoice_not_voidable', $err->errorCode);
        }
    }

    // ── usage ────────────────────────────────────────────────────────

    public function testUsageRecordAndReplay(): void
    {
        $c = $this->client();
        $price = $this->makePlan($c, 5, 'metered')['price'];
        $sub = $this->activeSubscription($c, (string) $price['id']);

        $key = IntegrationHarness::idemKey();
        $record = $c->subscriptions->createUsageRecord((string) $sub['id'], [
            'quantity' => 42,
            'metadata' => ['source' => 'php-it'],
            'idempotency_key' => $key,
        ]);
        self::assertSame('usage_record', $record['object']);
        self::assertSame($sub['id'], $record['subscription_id']);
        self::assertSame(42, $record['quantity']);
        self::assertNull($record['invoice_id']);

        // Replaying the same key must return the same record, not
        // double-count the usage: that is what makes at-least-once
        // reporting pipelines safe to retry.
        $replay = $c->subscriptions->createUsageRecord((string) $sub['id'], [
            'quantity' => 42,
            'metadata' => ['source' => 'php-it'],
            'idempotency_key' => $key,
        ]);
        self::assertSame($record['id'], $replay['id']);
    }

    public function testUsageListReconciliation(): void
    {
        $c = $this->client();
        $price = $this->makePlan($c, 3, 'metered')['price'];
        $sub = $this->activeSubscription($c, (string) $price['id']);

        $posted = [];
        foreach ([10, 20, 30] as $quantity) {
            $posted[] = $c->subscriptions->createUsageRecord(
                (string) $sub['id'],
                ['quantity' => $quantity],
            )['id'];
        }

        $pending = $c->subscriptions->listUsageRecords((string) $sub['id'], [
            'invoice_id' => 'pending',
            'limit' => 100,
        ]);
        self::assertSame('list', $pending['object']);
        $ids = array_column($pending['data'], 'id');
        foreach ($posted as $recordId) {
            self::assertContains($recordId, $ids);
        }
        // Nothing pending may already claim an invoice.
        foreach ($pending['data'] as $row) {
            self::assertNull($row['invoice_id']);
        }
    }

    public function testUsageNonMeteredRejected(): void
    {
        $c = $this->client();
        $price = $this->makePlan($c, 2500)['price'];
        $sub = $this->activeSubscription($c, (string) $price['id']);

        $this->expectException(InvalidRequestException::class);
        $c->subscriptions->createUsageRecord((string) $sub['id'], ['quantity' => 1]);
    }

    public function testUsageDedupeIdentifier(): void
    {
        $c = $this->client();
        $price = $this->makePlan($c, 7, 'metered')['price'];
        $sub = $this->activeSubscription($c, (string) $price['id']);

        $identifier = 'job-' . IntegrationHarness::idemKey();
        $first = $c->subscriptions->createUsageRecord((string) $sub['id'], [
            'quantity' => 9,
            'identifier' => $identifier,
            'idempotency_key' => IntegrationHarness::idemKey(),
        ]);
        // A DIFFERENT idempotency key, so the transport-level replay guard
        // cannot be what dedupes this. Only the natural key can.
        $second = $c->subscriptions->createUsageRecord((string) $sub['id'], [
            'quantity' => 9,
            'identifier' => $identifier,
            'idempotency_key' => IntegrationHarness::idemKey(),
        ]);
        self::assertSame($first['id'], $second['id']);

        $pending = $c->subscriptions->listUsageRecords((string) $sub['id'], [
            'invoice_id' => 'pending',
            'limit' => 100,
        ]);
        $ids = array_column($pending['data'], 'id');
        self::assertCount(1, array_keys($ids, $first['id'], true));
    }

    public function testUsageSummaryForecast(): void
    {
        $c = $this->client();
        $price = $this->makePlan($c, 11, 'metered')['price'];
        $sub = $this->activeSubscription($c, (string) $price['id']);
        foreach ([100, 250] as $quantity) {
            $c->subscriptions->createUsageRecord((string) $sub['id'], ['quantity' => $quantity]);
        }

        $summary = $c->subscriptions->retrieveUsageSummary((string) $sub['id']);
        self::assertSame($sub['id'], $summary['subscription_id']);
        self::assertSame(350, $summary['pending_quantity']);
        self::assertSame(2, $summary['pending_record_count']);
        // 350 units at 11 cents. The forecast and the close share one
        // predicate server-side, so this is the invoice, not an estimate.
        self::assertSame(3850, $summary['net_cents']);
        self::assertSame($summary['gross_cents'], $summary['net_cents'] + $summary['tax_cents']);
        self::assertTrue($summary['will_charge']);
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
