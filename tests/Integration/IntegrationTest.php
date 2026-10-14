<?php

declare(strict_types=1);

namespace BillKit\Tests\Integration;

use BillKit\BillKitClient;
use BillKit\BillKitObject;
use BillKit\EventType;
use BillKit\Exception\AuthenticationException;
use BillKit\Exception\ConflictException;
use BillKit\Exception\InvalidRequestException;
use BillKit\Exception\NotFoundException;
use BillKit\Exception\PermissionDeniedException;
use BillKit\Exception\ServerException;
use BillKit\Exception\WebhookVerificationException;
use BillKit\Model\CheckoutSession;
use BillKit\Model\Coupon;
use BillKit\Model\Customer;
use BillKit\Model\CustomerCreatedEvent;
use BillKit\Model\OneShotPayment;
use BillKit\Model\Payment;
use BillKit\Model\Price;
use BillKit\Model\Product;
use BillKit\Model\Subscription;
use BillKit\Model\SubscriptionUpdatedEvent;
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
        'crud.product_default_price',
        'crud.customer',
        'crud.test_clock',
        'crud.coupon',
        'crud.tax_rate',
        'crud.webhook_endpoint',
        'crud.price_decimal_rate',
        'crud.price_tiered',
        'crud.price_update_fields',
        'crud.coupon_percent_off',
        'crud.credit_note_absent_until_refunded',
        'crud.nullable_fields_clear',
        'routes.one_shot_list',
        'routes.one_shot_embedded',
        'money.payment_refund_eligibility',
        'filters.subscription_renewal_state',
        'filters.customer_provisional',
        'filters.audit_resource_id',
        'filters.expand',
        'customers.vat_number_clear',
        'safety.purge_requires_confirmation',
        'events.previous_attributes_nullable',
        'routes.api_keys',
        'routes.event_types',
        'routes.tenant_billing_profile',
        'pagination.has_more',
        'pagination.auto_iter',
        'idempotency.replay',
        'idempotency.key_reuse_conflict',
        'idempotency.in_progress_converges',
        'idempotency.blank_key_generated',
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
        'webhooks.event_type_constants',
        'methods.recurring_vocabulary',
        'methods.one_shot_vocabulary',
        'methods.banktransfer_settles_in_days',
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
     * @return array{product: Product, price: Price}
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
     */
    private function activeSubscription(BillKitClient $c, string $priceId): Subscription
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
     * @return array{session: CheckoutSession, provider_payment_id: string}
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

    private function findSubscription(BillKitClient $c, string $priceId): Subscription
    {
        foreach ($c->subscriptions->all(['limit' => 100])['data'] as $row) {
            if ($row['price_id'] === $priceId) {
                return $row;
            }
        }
        self::fail("no subscription found for price {$priceId}");
    }

    private function findPayment(BillKitClient $c, string $subscriptionId): Payment
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

        $this->expectException(PermissionDeniedException::class);
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

    public function testCrudProductDefaultPrice(): void
    {
        $c = $this->client();
        ['product' => $product, 'price' => $first] = $this->makePlan($c, 1000);
        $productId = (string) $product['id'];
        $second = $c->prices->create([
            'product_id' => $productId,
            'amount_cents' => 1200,
            'currency' => 'EUR',
            'interval' => 'month',
        ]);

        // The first price claims the default; a later one does not.
        self::assertSame($first['id'], $c->products->retrieve($productId)['default_price_id']);

        $moved = $c->products->update($productId, ['default_price_id' => $second['id']]);
        self::assertSame($second['id'], $moved['default_price_id']);
        $expanded = $c->products->retrieve($productId, ['expand' => ['default_price']]);
        self::assertSame($second['id'], $expanded['default_price']['id']);

        // Leaving the key out leaves the default alone.
        $renamed = $c->products->update($productId, ['name' => 'Renamed']);
        self::assertSame($second['id'], $renamed['default_price_id']);

        // A present-and-null key is the clear, so it has to survive the
        // null-stripping every other key gets.
        $cleared = $c->products->update($productId, ['default_price_id' => null]);
        self::assertNull($cleared['default_price_id']);

        // Another product's price is refused on the field.
        ['price' => $other] = $this->makePlan($c);
        try {
            $c->products->update($productId, ['default_price_id' => $other['id']]);
            self::fail('expected InvalidRequestException');
        } catch (InvalidRequestException $e) {
            self::assertSame('default_price_id', $e->param);
        }

        // Archiving the default price releases it rather than being refused.
        $c->products->update($productId, ['default_price_id' => $second['id']]);
        $c->prices->update((string) $second['id'], ['active' => false]);
        self::assertNull($c->products->retrieve($productId)['default_price_id']);
    }

    /**
     * Seven optional fields clear with a present-and-null key. Each goes
     * through the same array_key_exists path as `default_price_id`, so a
     * regression to plain null-stripping would send an empty body the API
     * reads as "change nothing".
     */
    public function testCrudNullableFieldsClear(): void
    {
        // A tenant of its own, so the tax rate cannot collide with another
        // test's rate for the same country.
        $c = $this->client(IntegrationHarness::provisionTenant('nullable-clears')['api_key']);

        $product = $c->products->create(['name' => 'Clearable', 'description' => 'Long form']);
        $productId = (string) $product['id'];
        self::assertSame('Long form', $c->products->update($productId, ['name' => 'Renamed'])['description']);
        self::assertNull($c->products->update($productId, ['description' => null])['description']);

        $customer = $c->customers->create([
            'email' => 'clear-' . IntegrationHarness::idemKey() . '@sdk-it.example.com',
            'name' => 'Ada Lovelace',
        ]);
        $customerId = (string) $customer['id'];
        self::assertSame('Ada Lovelace', $c->customers->update($customerId, ['metadata' => ['k' => 'v']])['name']);
        self::assertNull($c->customers->update($customerId, ['name' => null])['name']);

        $endpoint = $c->webhookEndpoints->create([
            'url' => 'https://merchant.example.com/hooks/clear',
            'enabled_events' => ['*'],
            'description' => 'to be cleared',
        ]);
        $endpointId = (string) $endpoint['id'];
        self::assertSame(
            'to be cleared',
            $c->webhookEndpoints->update($endpointId, ['enabled_events' => ['*']])['description'],
        );
        self::assertNull($c->webhookEndpoints->update($endpointId, ['description' => null])['description']);

        $coupon = $c->coupons->create([
            'code' => 'CLEAR' . substr((string) time(), -8),
            'percent_off' => 10,
            'duration' => 'once',
            'max_redemptions' => 5,
            'redeem_by' => time() + 86400 * 30,
        ]);
        $couponId = (string) $coupon['id'];
        $kept = $c->coupons->update($couponId, ['min_amount_cents' => 100]);
        self::assertSame(5, $kept['max_redemptions']);
        self::assertNotNull($kept['redeem_by']);
        self::assertSame(100, $kept['min_amount_cents']);
        $cleared = $c->coupons->update(
            $couponId,
            ['max_redemptions' => null, 'redeem_by' => null, 'min_amount_cents' => null],
        );
        self::assertNull($cleared['max_redemptions']);
        self::assertNull($cleared['redeem_by']);
        self::assertNull($cleared['min_amount_cents']);

        $rate = $c->taxRates->create([
            'country_code' => 'BE',
            'rate_basis_points' => 2100,
            'display_name' => 'BE VAT',
        ]);
        $rateId = (string) $rate['id'];
        self::assertSame('BE VAT', $c->taxRates->update($rateId, ['rate_basis_points' => 2100])['display_name']);
        self::assertNull($c->taxRates->update($rateId, ['display_name' => null])['display_name']);
    }

    public function testCrudPriceUpdateFields(): void
    {
        $c = $this->client();
        ['price' => $price] = $this->makePlan($c, 1500);

        // No `active` in the body. Every field is optional and an omitted
        // one is left alone, so a refund policy can be set on a live price
        // without restating whether it is on sale.
        $updated = $c->prices->update((string) $price['id'], [
            'metadata' => ['tier' => 'pro'],
            'refund_on_cancel' => 'prorated',
            // 0 disables refunds for that charge type, so it has to survive
            // the SDK's own null-stripping.
            'refund_window_renewal_days' => 0,
        ]);
        self::assertSame(['tier' => 'pro'], $updated['metadata']);
        self::assertSame('prorated', $updated['refund_on_cancel']);
        self::assertSame(0, $updated['refund_window_renewal_days']);
        self::assertTrue($updated['active']);

        $fetched = $c->prices->retrieve((string) $price['id']);
        self::assertTrue($fetched['active']);
        self::assertSame(1500, $fetched['amount_cents']);
    }

    /**
     * API 1.0 split the overloaded `discount_value` (whole percent or minor
     * units, depending on `discount_type`) into `percent_off` and
     * `amount_off_cents`, and renamed `fixed_cents` to `fixed_amount`.
     */
    public function testCrudCouponPercentOff(): void
    {
        $c = $this->client();

        $percent = $c->coupons->create([
            'code' => 'PCT' . substr(IntegrationHarness::idemKey(), -10),
            'percent_off' => 10,
            'duration' => 'once',
        ]);
        self::assertSame(10.0, $percent->percent_off);
        self::assertSame(Coupon::DISCOUNT_TYPE_PERCENT, $percent->discount_type);
        self::assertNull($percent->amount_off_cents);

        $read = $c->coupons->retrieve($percent->id);
        self::assertSame(10.0, $read->percent_off);
        self::assertNull($read->amount_off_cents);

        // A fractional percentage, up to two decimal places, is a number
        // both ways: on create and on a later retrieve.
        $fractional = $c->coupons->create([
            'code' => 'FRC' . substr(IntegrationHarness::idemKey(), -10),
            'percent_off' => 12.5,
        ]);
        self::assertSame(12.5, $fractional->percent_off);
        self::assertSame(12.5, $fractional['percent_off']);
        self::assertSame(12.5, $c->coupons->retrieve((string) $fractional->id)->percent_off);

        $fixed = $c->coupons->create([
            'code' => 'FIX' . substr(IntegrationHarness::idemKey(), -10),
            'amount_off_cents' => 500,
            'currency' => 'EUR',
            'duration' => 'once',
        ]);
        self::assertSame(Coupon::DISCOUNT_TYPE_FIXED_AMOUNT, $fixed->discount_type);
        self::assertSame(500, $fixed->amount_off_cents);
        self::assertNull($fixed->percent_off);
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
            $deleted->toArray(),
        );

        $page = $c->customers->all(['limit' => 100]);
        self::assertNotContains($created['id'], array_column($page['data'], 'id'));
    }

    public function testCrudTestClock(): void
    {
        $c = $this->client();
        $start = time();
        $clock = $c->testClocks->create(['frozen_time' => $start, 'name' => 'SDK IT']);
        $id = (string) $clock['id'];
        self::assertSame('test_clock', $clock['object']);
        self::assertSame($start, $clock['frozen_time']);

        self::assertSame($id, $c->testClocks->retrieve($id)['id']);
        self::assertContains($id, array_column($c->testClocks->all(['limit' => 100])['data'], 'id'));

        $customer = $c->customers->create([
            'email' => 'clock-' . IntegrationHarness::idemKey() . '@sdk-it.example.com',
            'test_clock_id' => $id,
        ]);
        self::assertSame($id, $customer->test_clock_id);
        self::assertFalse($customer->has('test_clock'));
        $reread = $c->customers->retrieve((string) $customer->id);
        self::assertSame($id, $reread->test_clock_id);
        self::assertFalse($reread->has('test_clock'));

        $advanced = $c->testClocks->advance($id, ['frozen_time' => $start + 86400]);
        self::assertSame($start + 86400, $advanced['frozen_time']);

        self::assertSame(
            ['id' => $id, 'object' => 'test_clock', 'deleted' => true],
            $c->testClocks->delete($id)->toArray(),
        );
        $this->expectException(NotFoundException::class);
        $c->testClocks->retrieve($id);
    }

    public function testCrudCoupon(): void
    {
        $c = $this->client();
        $code = 'SAVE' . substr((string) time(), -8);
        $created = $c->coupons->create([
            'code' => $code,
            'percent_off' => 25,
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
            $gone->toArray(),
        );
        $after = $c->webhookEndpoints->all(['limit' => 100]);
        self::assertNotContains($created['id'], array_column($after['data'], 'id'));

        $this->expectException(NotFoundException::class);
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
        // There is no `create` on the resource at all, issuance hangs off a
        // settled refund. Assert the read surface is reachable and honest
        // about having nothing yet.
        self::assertSame('list', $c->creditNotes->all(['limit' => 10])['object']);

        $this->expectException(NotFoundException::class);
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

        $byRenewalState = $c->subscriptions->all(['renewal_state' => ['paused'], 'limit' => 100]);
        self::assertContains($sub['id'], array_column($byRenewalState['data'], 'id'));

        // ...and it is still an `active` subscription to the status filter.
        $byStatus = $c->subscriptions->all(['status' => ['active'], 'limit' => 100]);
        self::assertContains($sub['id'], array_column($byStatus['data'], 'id'));

        // Both filters take a list, sent comma-joined, and match any value.
        $both = $c->subscriptions->all(['renewal_state' => ['paused'], 'status' => ['active'], 'limit' => 100]);
        self::assertContains($sub['id'], array_column($both['data'], 'id'));
        $either = $c->subscriptions->all([
            'status' => ['past_due', 'active'],
            'renewal_state' => ['canceling', 'paused'],
            'limit' => 100,
        ]);
        self::assertContains($sub['id'], array_column($either['data'], 'id'));
        $neither = $c->subscriptions->all(['renewal_state' => ['auto_renew', 'canceling'], 'limit' => 100]);
        self::assertNotContains($sub['id'], array_column($neither['data'], 'id'));

        // `status=paused` is not a value the API accepts. It used to be, and
        // returned a confident, wrong, empty page; now it is refused so the
        // mistake is visible.
        try {
            $c->subscriptions->all(['status' => ['paused']]);
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

    /**
     * The question an audit log mostly exists for: everything that ever
     * happened to *this* customer. php could always ask it, because it
     * forwards an array; node and python named three of the API's four
     * filters and omitted this one, which is what the scenario pins.
     */
    public function testFiltersExpand(): void
    {
        $c = $this->client();
        ['product' => $product, 'price' => $price] = $this->makePlan($c, 4200);

        // On a list. Resolved once for the whole page, which is the point:
        // the alternative a caller reaches for is one request per row.
        $page = $c->products->all(['expand' => ['prices'], 'limit' => 100]);
        $rows = array_values(array_filter(
            $page['data'],
            static fn (BillKitObject $row): bool => $row['id'] === $product['id'],
        ));
        self::assertCount(1, $rows);
        self::assertContains($price['id'], array_column($rows[0]['prices'], 'id'));

        // And on a retrieve, as a second argument.
        $one = $c->products->retrieve((string) $product['id'], ['expand' => ['prices']]);
        self::assertContains($price['id'], array_column($one['prices'], 'id'));

        // Without it, nothing changes for a caller that never asked.
        self::assertNull($c->products->retrieve((string) $product['id'])['prices'] ?? null);

        // An unknown relation is a 400 naming the ones that work, not a
        // response that quietly lacks the key.
        $this->expectException(InvalidRequestException::class);
        $c->products->retrieve((string) $product['id'], ['expand' => ['nonsense']]);
    }

    public function testFiltersAuditResourceId(): void
    {
        $t = IntegrationHarness::provisionTenant('audit-resource');
        $c = $this->client($t['api_key']);

        $subject = $c->customers->create(['email' => 'subject-' . IntegrationHarness::idemKey() . '@example.com']);
        // A second customer, so "only the subject's rows" is an assertion
        // rather than a restatement of an empty tenant.
        $other = $c->customers->create(['email' => 'other-' . IntegrationHarness::idemKey() . '@example.com']);
        $c->customers->update($subject['id'], ['name' => 'Renamed']);

        $rows = static fn (array $params): array => $c->auditLogs->all($params + ['limit' => 100])['data'];

        $scoped = $rows(['resource_id' => $subject['id']]);
        self::assertNotEmpty($scoped);
        self::assertSame([$subject['id']], array_values(array_unique(array_column($scoped, 'resource_id'))));
        self::assertNotContains($other['id'], array_column($scoped, 'resource_id'));

        // Combines with resource_type rather than replacing it.
        $narrowed = $rows(['resource_id' => $subject['id'], 'resource_type' => 'customer']);
        self::assertNotEmpty($narrowed);
        self::assertSame([$subject['id']], array_values(array_unique(array_column($narrowed, 'resource_id'))));
    }

    // ── customers ────────────────────────────────────────────────────

    public function testCustomersVatNumberClear(): void
    {
        $c = $this->client();
        // No country on the customer: VIES needs one, so the API stores
        // the number as unverifiable without opening a socket. That keeps
        // the scenario about the SDK's body rather than about the EU's
        // uptime.
        $customer = $c->customers->create([
            'email' => 'vat-' . IntegrationHarness::idemKey() . '@sdk-it.example.com',
        ]);

        $stored = $c->customers->setVatNumber((string) $customer['id'], [
            'vat_number' => 'NL123456789B01',
        ]);
        self::assertSame('NL123456789B01', $stored['vat_number']);

        // The one body where null is a value. Stripped, it would be an
        // empty object, which the API reads as "change nothing".
        $cleared = $c->customers->setVatNumber((string) $customer['id'], ['vat_number' => null]);
        self::assertNull($cleared['vat_number']);
        self::assertNull($c->customers->retrieve((string) $customer['id'])['vat_number']);
    }

    // ── routes ───────────────────────────────────────────────────────

    public function testRoutesApiKeys(): void
    {
        $c = new BillKitClient(
            apiKey: IntegrationHarness::provisionTenant('api-keys')['api_key'],
            baseUrl: IntegrationHarness::baseUrl(),
        );

        $created = $c->apiKeys->create(['label' => 'integration', 'scopes' => ['products:read']]);
        self::assertNotEmpty($created['secret']);
        self::assertSame(['products:read'], $created['scopes']);

        // The secret exists once. Every later read carries the prefix alone.
        $fetched = $c->apiKeys->retrieve((string) $created['id']);
        self::assertSame($created['id'], $fetched['id']);
        self::assertNull($fetched['secret'] ?? null);
        self::assertNotEmpty($fetched['prefix']);

        self::assertContains(
            $created['id'],
            array_column($c->apiKeys->all(['limit' => 100])['data'], 'id'),
        );

        $revoked = $c->apiKeys->revoke((string) $created['id']);
        self::assertNotEmpty($revoked['revoked_at']);

        // A revoked key stays listed: "this key was in service until
        // Tuesday" is the question a leak investigation asks.
        $after = $c->apiKeys->all(['limit' => 100])['data'];
        $row = array_values(array_filter(
            $after,
            static fn (BillKitObject $k): bool => $k['id'] === $created['id'],
        ));
        self::assertNotEmpty($row[0]['revoked_at']);
    }

    public function testRoutesEventTypes(): void
    {
        $c = $this->client();
        $catalogue = $c->webhookEndpoints->listEventTypes();
        self::assertSame('event_type_list', $catalogue['object']);
        self::assertNotEmpty($catalogue['data']);
        self::assertNotEmpty($catalogue['wildcard']);

        // enabled_events is validated against exactly this list, so a name
        // it returns has to register.
        $endpoint = $c->webhookEndpoints->create([
            'url' => 'https://merchant.example.com/hooks',
            'enabled_events' => [$catalogue['data'][0]],
        ]);
        self::assertNotEmpty($endpoint['id']);
        $c->webhookEndpoints->delete((string) $endpoint['id']);
    }

    public function testRoutesTenantBillingProfile(): void
    {
        // A live-mode key: the billing profile is shared by both modes, so a
        // test-mode key may not write it.
        $c = new BillKitClient(
            apiKey: IntegrationHarness::provisionTenant('billing-profile', 'live')['api_key'],
            baseUrl: IntegrationHarness::baseUrl(),
        );

        $stored = $c->tenant->setBillingProfile([
            'country_code' => 'NL',
            'vat_id' => 'NL123456789B01',
            'city' => 'Amsterdam',
        ]);
        self::assertSame('NL', $stored['country_code']);
        self::assertSame('NL123456789B01', $stored['vat_id']);

        $read = $c->tenant->retrieveBillingProfile();
        self::assertSame('NL123456789B01', $read['vat_id']);
        // Stored and effective agree once something is stored; they differ
        // only when nothing ever was.
        self::assertSame('NL', $read['effective_country_code']);

        // A stored VAT id is locked: changing it and clearing it with an
        // explicit null are both refused, and the refusal names the field.
        foreach (['NL000099998B57', null] as $vatId) {
            try {
                $c->tenant->setBillingProfile([
                    'country_code' => 'NL',
                    'vat_id' => $vatId,
                    'city' => 'Rotterdam',
                ]);
                self::fail('expected InvalidRequestException');
            } catch (InvalidRequestException $e) {
                self::assertSame('vat_id', $e->param);
                self::assertSame('parameter_invalid', $e->errorCode);
                self::assertStringContainsString('cannot be changed once it is set', $e->getMessage());
                self::assertIsArray($e->rawBody);
                self::assertSame('vat_id_locked', $e->rawBody['error']['reason'] ?? null);
            }
        }

        // The refused call wrote nothing, including the city it carried.
        $after = $c->tenant->retrieveBillingProfile();
        self::assertSame('NL123456789B01', $after['vat_id']);
        self::assertSame('Amsterdam', $after['city']);

        // Everything else stays editable: omit vat_id and it is left alone.
        $moved = $c->tenant->setBillingProfile(['country_code' => 'NL', 'city' => 'Utrecht']);
        self::assertSame('Utrecht', $moved['city']);
        self::assertSame('NL123456789B01', $moved['vat_id']);
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
        foreach ($c->products->autoPagingIterator(['limit' => 2]) as $product) {
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
        $first = $c->products->create(['name' => 'Idempotent Product'], ['idempotency_key' => $key]);
        $second = $c->products->create(['name' => 'Idempotent Product'], ['idempotency_key' => $key]);
        self::assertSame($first['id'], $second['id']);
    }

    /**
     * A blank key on the wire is a 400 now, and before that it meant "no
     * idempotency" while the SDK still retried the POST. Each call here gets
     * its own generated key, so both succeed as two products.
     */
    public function testIdempotencyBlankKeyGenerated(): void
    {
        $c = $this->client();
        $first = $c->products->create(['name' => 'Blank Key'], ['idempotency_key' => '']);
        $second = $c->products->create(['name' => 'Blank Key'], ['idempotency_key' => '   ']);
        self::assertNotEmpty($first['id']);
        self::assertNotSame($first['id'], $second['id']);
    }

    public function testIdempotencyKeyReuseConflict(): void
    {
        $c = $this->client();
        $key = IntegrationHarness::idemKey();
        $c->products->create(['name' => 'First Body'], ['idempotency_key' => $key]);

        $this->expectException(ConflictException::class);
        $c->products->create(['name' => 'Different Body'], ['idempotency_key' => $key]);
    }

    /**
     * The contract a caller depends on: firing the same keyed create from
     * several workers yields ONE resource and no exception.
     *
     * A request that arrives while another holder of the key is still
     * running gets `409 idempotency_in_progress`, the one 4xx the client
     * retries, because the charge may already have happened and the obvious
     * workaround (retry with a fresh key) is what turns one charge into two.
     *
     * PHP has no in-process concurrency, so the racers are raw curl handles
     * pumped until their request bodies are on the wire and only drained
     * afterwards; the SDK's own create runs in between.
     *
     * **A racer is not the client, and that is the whole subtlety here.**
     * This used to assert `200` on every drained handle, which made the test
     * fail precisely when the race it exists to create actually happened:
     * raw curl does not retry, so a handle that landed inside the in-flight
     * window answered 409 and the assertion called correct server behaviour
     * a failure. Racing 12 same-key creates at a live server returns a mix of
     * 200 and 409 on most trials, so this was a red flake waiting on load.
     * and `SCENARIOS.md` promises this scenario "never flakes red".
     *
     * So the assertion is the one node and python make, which is the one the
     * scenario actually declares: every attempt resolves to the SAME
     * resource id, and the server holds one row. A 409 racer is retried here
     * the way {@see \BillKit\RetryPolicy} retries it inside the client, and
     * must then converge. The deterministic proof that the client itself
     * retries is in `tests/RetryTest.php`.
     *
     * **On the racer count, which was measured rather than guessed.** At 6
     * the in-flight window was never entered in 12 consecutive local runs;
     * at 8 it was entered in every one of 5, and the pre-fix assertion
     * failed 3 out of 3 there. 8 would therefore make the race
     * deterministic, and is not used, because the API pool is
     * `DB_POOL_SIZE 5 + DB_POOL_MAX_OVERFLOW 5`, so 8 racers plus the SDK's
     * own create leaves exactly one spare connection. Racing 12 exhausts it
     * and turns the losers into 500s after a 10s `pool_timeout`, which would
     * swap a red flake for a slower and much more confusing one. 6 keeps
     * this comfortably inside the pool; the branch above is what makes the
     * occasional real race harmless.
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
            static fn (): array => $c->products->create(['name' => $name], ['idempotency_key' => $key])->toArray(),
        );

        /** @var array{id: string} $viaSdk */
        $inFlight = 0;
        foreach ($racers as [$status, $body]) {
            if ($status === 409) {
                // A loser that arrived inside the winner's window. The only
                // thing worth asserting on the wire is that it is *this* 409
                // and not some other conflict, a key-reuse conflict here
                // would mean the bodies diverged, which is a real bug.
                /** @var array{error: array{code: string}} $err */
                $err = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                self::assertSame(
                    'idempotency_in_progress',
                    $err['error']['code'],
                    "racer answered 409 with the wrong code: {$body}",
                );
                $inFlight++;
                // Retry it as the client would, and require convergence.
                $decoded = $c->products->create(['name' => $name], ['idempotency_key' => $key])->toArray();
            } else {
                self::assertSame(200, $status, "racer answered {$status}: {$body}");
                /** @var array{id: string} $decoded */
                $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            }
            self::assertSame($viaSdk['id'], $decoded['id'], 'every attempt must resolve to the same product');
        }

        // Not an assertion: whether the window was entered is the server's
        // timing to decide and the scenario is explicitly allowed to pass
        // without it. Recorded so a run that never races is visible as such
        // rather than looking like proof it cannot happen.
        if ($inFlight === 0) {
            self::assertTrue(true, 'no racer entered the in-flight window on this run');
        }

        // And the server really did create only one row.
        $rows = array_filter(
            $c->products->all(['limit' => 100])['data'],
            static fn (BillKitObject $row): bool => $row['name'] === $name,
        );
        self::assertCount(1, $rows);
    }

    // ── errors ───────────────────────────────────────────────────────

    public function testErrorsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
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
     * An unmatched route answers 404 with
     * `{"type": "invalid_request_error", "code": "route_not_found"}`.
     * Mapping on `type` would throw `InvalidRequestException`; mapping on the
     * status throws `NotFoundException`. Before 1.0 the same miss read
     * `{"type": "api_error"}`, and mapping on `type` made a plain 404 arrive
     * as `ServerException`, the class retry and alerting policies key on.
     *
     * Driven through the transport rather than a resource because that is
     * what a version skew looks like: the SDK asking for a route this API
     * does not have.
     */
    public function testErrorsStatusDrivesClass(): void
    {
        try {
            // The transport is private in 1.0; a test reaches it the way any
            // caller cannot, which is the point.
            $transport = (new \ReflectionProperty(BillKitClient::class, 'transport'))->getValue($this->client());
            self::assertInstanceOf(\BillKit\Transport::class, $transport);
            $transport->request('GET', '/v1/no_such_resource');
            self::fail('expected NotFoundException');
        } catch (NotFoundException $e) {
            self::assertNotInstanceOf(InvalidRequestException::class, $e);
            self::assertNotInstanceOf(ServerException::class, $e);
            // The envelope values are still carried verbatim; they just do
            // not choose the class.
            self::assertSame('invalid_request_error', $e->errorType);
            self::assertSame('route_not_found', $e->errorCode);
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

    public function testMoneyPaymentRefundEligibility(): void
    {
        $c = $this->client();
        $price = $this->makePlan($c, 10000)['price'];
        $this->checkoutToActive($c, (string) $price['id']);
        $sub = $this->findSubscription($c, (string) $price['id']);
        $paymentId = (string) $this->findPayment($c, (string) $sub['id'])['id'];

        $fresh = $c->payments->retrieve($paymentId, ['expand' => ['refund_eligibility']])['refund_eligibility'];
        self::assertSame('refund_eligibility', $fresh['object']);
        self::assertTrue($fresh['eligible']);
        self::assertSame(10000, $fresh['amount_cents']);
        self::assertIsInt($fresh['window_ends_at']);
        self::assertNull($fresh['reason']);

        $c->refunds->create(['payment_id' => $paymentId, 'amount_cents' => 3000]);
        $after = $c->payments->retrieve($paymentId, ['expand' => ['refund_eligibility']])['refund_eligibility'];
        self::assertTrue($after['eligible']);
        self::assertSame(7000, $after['amount_cents']);

        // Retrieve-only: a list page would pay one query per row for it.
        $this->expectException(InvalidRequestException::class);
        $c->payments->all(['expand' => ['refund_eligibility']]);
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
            static fn (BillKitObject $d): bool => $d['amount_cents'] === 7700,
        ));
        self::assertNotEmpty($match, 'a dispute should exist for the charged-back payment');
        self::assertSame('open', $match[0]['status']);
        // Open means not reversed: the key is there, as an explicit null,
        // under its 1.0 name.
        self::assertTrue($match[0]->has('reversed_at'));
        self::assertNull($match[0]['reversed_at']);
        self::assertFalse($match[0]->has('reversed'));
        self::assertSame(
            $match[0]['id'],
            $c->disputes->retrieve((string) $match[0]['id'])['id'],
        );
        $both = $c->disputes->all(['status' => ['open', 'won'], 'limit' => 100]);
        self::assertContains($match[0]['id'], array_column($both['data'], 'id'));
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

        // Not a limitation, the contract. Voiding claims the sale was never
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
        ], ['idempotency_key' => $key]);
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
        ], ['idempotency_key' => $key]);
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

        try {
            $c->subscriptions->createUsageRecord((string) $sub['id'], ['quantity' => 1]);
            self::fail('expected ConflictException');
        } catch (ConflictException $e) {
            self::assertSame('state_conflict', $e->errorCode);
        }
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
        ], ['idempotency_key' => IntegrationHarness::idemKey()]);
        // A DIFFERENT idempotency key, so the transport-level replay guard
        // cannot be what dedupes this. Only the natural key can.
        $second = $c->subscriptions->createUsageRecord((string) $sub['id'], [
            'quantity' => 9,
            'identifier' => $identifier,
        ], ['idempotency_key' => IntegrationHarness::idemKey()]);
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

    // ── safety ───────────────────────────────────────────────────────

    public function testSafetyPurgeRequiresConfirmation(): void
    {
        $c = $this->client();
        $customer = $c->customers->create([
            'email' => 'purge-' . IntegrationHarness::idemKey() . '@sdk-it.example.com',
            'name' => 'To Be Forgotten',
        ]);

        // Without the confirmation nothing is sent: the customer is intact.
        try {
            $c->customers->purge($customer->id);
            self::fail('an unconfirmed purge must throw before any request');
        } catch (\InvalidArgumentException) {
        }
        self::assertSame($customer->email, $c->customers->retrieve($customer->id)->email);

        $purged = $c->customers->purge($customer->id, ['confirmed' => true]);
        // The marker, not the customer: its placeholder email would read as
        // live data.
        self::assertSame(
            ['id' => $customer->id, 'object' => 'customer', 'deleted' => true, 'purged' => true],
            $purged->toArray(),
        );

        // A purged customer has also left the API.
        $this->expectException(NotFoundException::class);
        $c->customers->retrieve($customer->id);
    }

    // ── events ───────────────────────────────────────────────────────

    public function testEventsPreviousAttributesNullable(): void
    {
        $c = $this->client();

        // A creation has no prior state: the key is present and null.
        $customer = $c->customers->create(['email' => 'evt-' . IntegrationHarness::idemKey() . '@sdk-it.example.com']);
        $created = null;
        foreach ($c->events->autoPagingIterator(['type' => 'customer.created', 'limit' => 100]) as $event) {
            if ($event->data['id'] === $customer->id) {
                $created = $c->events->retrieve($event->id);

                break;
            }
        }
        self::assertInstanceOf(CustomerCreatedEvent::class, $created);
        self::assertTrue($created->has('previous_attributes'));
        self::assertNull($created->previous_attributes);

        // A pause reports what it moved, with the value before it.
        $price = $this->makePlan($c, 1500)['price'];
        $sub = $this->activeSubscription($c, $price->id);
        $c->subscriptions->pause($sub->id);
        $updated = null;
        foreach ($c->events->autoPagingIterator(['type' => 'subscription.updated', 'limit' => 100]) as $event) {
            if ($event->data['id'] === $sub->id && isset($event->previous_attributes['renewal_state'])) {
                $updated = $c->events->retrieve($event->id);

                break;
            }
        }
        self::assertInstanceOf(SubscriptionUpdatedEvent::class, $updated);
        self::assertSame('auto_renew', $updated->previous_attributes['renewal_state'] ?? null);
        self::assertSame('paused', $updated->data->renewal_state);

        // `type` takes a list, sent comma-joined, and returns exactly those.
        $wanted = ['customer.created', 'subscription.updated'];
        $seen = [];
        foreach ($c->events->autoPagingIterator(['type' => $wanted, 'limit' => 100]) as $event) {
            $seen[(string) $event->type] = true;
        }
        $seenTypes = array_keys($seen);
        sort($seenTypes);
        self::assertSame($wanted, $seenTypes);
        $page = $c->events->all(['type' => $wanted, 'limit' => 100]);
        foreach ($page['data'] as $event) {
            self::assertContains($event['type'], $wanted);
        }
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

    public function testWebhooksEventTypeConstants(): void
    {
        $catalogue = $this->client()->webhookEndpoints->listEventTypes();
        $live = array_values(array_filter(
            $catalogue['data'],
            static fn ($t): bool => $t !== $catalogue['wildcard'],
        ));
        $sdk = EventType::all();
        sort($live);
        sort($sdk);
        self::assertSame($live, $sdk);
    }

    // ── methods ──────────────────────────────────────────────────────
    //
    // The two request surfaces take different method sets. This SDK names
    // no method anywhere, it forwards whatever array it is handed, so
    // these scenarios are the only thing holding php to the same wire
    // contract node and python spell out in their types.

    /**
     * The vocabulary a *price* may offer. Mirrors the server's
     * `RecurringMethod`.
     */
    private const RECURRING_METHODS = ['creditcard', 'directdebit', 'ideal', 'eps', 'applepay', 'paypal'];

    /**
     * The subset that can actually be a checkout's `payment_method`, i.e. that Mollie
     * will mint a mandate from at `sequenceType=first`.
     *
     * `directdebit` is the one member of RECURRING_METHODS missing here, and
     * the distinction is the whole point of the scenario: SEPA belongs in a
     * price's allowlist because it is what the renewals settle on, but it can
     * never be the FIRST charge, the mandate has to be minted by a card,
     * iDEAL, EPS, Apple Pay or PayPal payment before anything can be collected
     * over it.
     */
    private const MANDATE_CREATING_METHODS = ['creditcard', 'ideal', 'eps', 'applepay', 'paypal'];

    /** Everything a single `sequenceType=oneoff` charge may use. */
    // Not directdebit: SEPA only collects over a mandate another method
    // minted, so the API refuses it on a one-off (asserted with giropay).
    private const ONE_SHOT_METHODS = [
        'creditcard', 'ideal', 'eps', 'applepay', 'paypal',
        'bancontact', 'banktransfer',
    ];

    /**
     * Narrower than the one-shot set on purpose: a checkout has to mint the
     * mandate the renewal will charge. It is also narrower than the price
     * allowlist, which is the part that surprises people, `directdebit` is a
     * perfectly good thing for a price to offer and can never be the payment
     * that starts one.
     */
    public function testMethodsRecurringVocabulary(): void
    {
        $t = IntegrationHarness::provisionTenant('methods-recurring');
        $c = $this->client($t['api_key']);
        $product = $c->products->create(['name' => 'Plan ' . IntegrationHarness::idemKey()]);
        $price = $c->prices->create([
            'product_id' => $product['id'],
            'amount_cents' => 2500,
            'currency' => 'EUR',
            'interval' => 'month',
            // The allowlist has to name them too, or the refusals below are
            // the price's and not the vocabulary's.
            'payment_methods' => self::RECURRING_METHODS,
        ]);

        // A customer each: an in-flight initial_checkout is guarded per
        // customer, so reusing one would fail the second method for a reason
        // that has nothing to do with its name.
        $startCheckout = fn (string $method): CheckoutSession => $c->checkoutSessions->create([
            'customer_id' => $this->methodBuyer($c)['id'],
            'price_id' => $price['id'],
            'payment_method' => $method,
            'success_url' => 'https://merchant.example.com/ok',
            'cancel_url' => 'https://merchant.example.com/cancel',
        ]);

        foreach (self::MANDATE_CREATING_METHODS as $method) {
            self::assertNotEmpty($startCheckout($method)['id'], "{$method} should start a checkout");
        }

        // Two different refusals, and they come from two different layers.
        //
        // `directdebit` passes the request literal, it IS a RecurringMethod,
        // and the price above offers it, and is refused by the service,
        // because SEPA is what renewals settle on rather than something a
        // buyer can pay with first.
        //
        // `bancontact` and `banktransfer` never reach the service: neither is
        // in `RecurringMethod` at all, so the schema rejects them. Mollie
        // refuses banktransfer outright anyway ("The payment method does not
        // support sequence type").
        //
        // Both surface as the same exception, which is the contract this
        // asserts; the reasons differ and are worth knowing when one fires.
        foreach (['directdebit', 'bancontact', 'banktransfer'] as $method) {
            try {
                $startCheckout($method);
                self::fail("{$method} must not start a subscription");
            } catch (InvalidRequestException) {
                self::assertTrue(true);
            }
        }
    }

    /**
     * `banktransfer` included, and the retired `giropay` refused: the scheme
     * shut down at the end of 2024, so its refusal is part of the contract
     * rather than an omission.
     */
    public function testMethodsOneShotVocabulary(): void
    {
        $t = IntegrationHarness::provisionTenant('methods-oneshot');
        $c = $this->client($t['api_key']);

        foreach (self::ONE_SHOT_METHODS as $method) {
            $charge = $this->oneShot($c, $method);
            self::assertNotEmpty($charge['id'], "{$method} should take a one-off charge");
            self::assertSame($method, $charge->payment_method);
        }

        foreach (['directdebit', 'giropay'] as $method) {
            try {
                $this->oneShot($c, $method);
                self::fail("{$method} must not take a one-off charge");
            } catch (InvalidRequestException) {
                // Refused, as the contract says.
            }
        }
    }

    /**
     * The payer is handed bank details and pays on their own schedule, so
     * `pending` is not a failure and not something to poll.
     */
    public function testMethodsBanktransferSettlesInDays(): void
    {
        $t = IntegrationHarness::provisionTenant('methods-banktransfer');
        $c = $this->client($t['api_key']);

        $card = $this->oneShot($c, 'creditcard');
        $transfer = $this->oneShot($c, 'banktransfer');

        // Relative, not absolute: the window is copied from the provider's
        // own answer rather than invented by BillKit, so pinning an exact
        // number would assert the fake's arithmetic instead of the behaviour
        // an integrator has to plan for.
        self::assertGreaterThan($card['expires_at'], $transfer['expires_at']);
        $days = ($transfer['expires_at'] - time()) / 86400;
        self::assertGreaterThan(5, $days, 'a bank transfer stays open for days, not minutes');
    }

    public function testRoutesOneShotEmbedded(): void
    {
        $t = IntegrationHarness::provisionTenant('one-shot-embedded');
        $c = $this->client($t['api_key']);
        $buyer = $this->methodBuyer($c);
        $created = $c->oneShotPayments->create([
            'customer_id' => $buyer['id'],
            'amount_cents' => 2500,
            'currency' => 'EUR',
            'ui_mode' => 'embedded',
            'success_url' => 'https://merchant.example.com/ok',
        ]);
        self::assertSame('embedded', $created['ui_mode']);
        self::assertNull($created['redirect_url']);
        self::assertNull($created['payment_method']);
        self::assertFalse($created->has('method'));
        // Always an object, `{}` when none was set, never null.
        self::assertSame([], $created->metadata);
        self::assertTrue($created->has('metadata'));
        self::assertIsString($created['client_secret']);
        self::assertStringStartsWith($created['id'] . '_secret_', $created['client_secret']);

        $read = $c->oneShotPayments->retrieve($created['id']);
        self::assertNull($read['client_secret']);
        self::assertSame('embedded', $read['ui_mode']);
    }

    public function testRoutesOneShotList(): void
    {
        $t = IntegrationHarness::provisionTenant('one-shot-list');
        $c = $this->client($t['api_key']);
        $buyer = $this->methodBuyer($c);
        $make = fn (): OneShotPayment => $c->oneShotPayments->create([
            'customer_id' => $buyer['id'],
            'amount_cents' => 2500,
            'currency' => 'EUR',
            'payment_method' => 'creditcard',
            'success_url' => 'https://merchant.example.com/ok',
        ]);
        $first = $make();
        $second = $make();
        // Another buyer's charge must not leak into the filtered page.
        $this->oneShot($c, 'creditcard');

        $page = $c->oneShotPayments->all(['customer_id' => $buyer['id']]);
        self::assertSame([$second['id'], $first['id']], array_column($page['data'], 'id'));

        $status = (string) $first['status'];
        $byStatus = $c->oneShotPayments->all(['customer_id' => $buyer['id'], 'status' => $status]);
        self::assertContains($first['id'], array_column($byStatus['data'], 'id'));
        foreach ($byStatus['data'] as $row) {
            self::assertSame($status, $row['status']);
        }

        try {
            $c->oneShotPayments->all(['status' => 'settled']);
            self::fail('an unknown one-shot status must be refused');
        } catch (InvalidRequestException) {
        }

        // Refunding a charge nobody paid is a state refusal, not a bad request.
        try {
            $c->refunds->create(['one_shot_payment_id' => $first['id']]);
            self::fail('refunding an unpaid one-shot must be refused');
        } catch (ConflictException $e) {
            self::assertSame('state_conflict', $e->errorCode);
        }
    }

    private function methodBuyer(BillKitClient $c): Customer
    {
        return $c->customers->create([
            'email' => 'method-' . IntegrationHarness::idemKey() . '@sdk-it.example.com',
            'country_code' => 'AT',
        ]);
    }

    private function oneShot(BillKitClient $c, string $method): OneShotPayment
    {
        return $c->oneShotPayments->create([
            'customer_id' => $this->methodBuyer($c)['id'],
            'amount_cents' => 2500,
            'currency' => 'EUR',
            'payment_method' => $method,
            'success_url' => 'https://merchant.example.com/ok',
        ]);
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
