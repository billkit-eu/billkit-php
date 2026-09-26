<?php

declare(strict_types=1);

namespace BillKit\Tests;

use BillKit\Tests\Support\MockHttpClient;

/**
 * Path/method/body assertions across the less-obvious resource verbs, so
 * a typo in a URL template is caught. Mirrors the Node SDK's
 * ``resources.test.ts``.
 */
final class ResourcesTest extends BillKitTestCase
{
    public function testTenantCapabilitiesIsGet(): void
    {
        $http = (new MockHttpClient())->stage(200, ['methods' => []]);
        $this->makeClient($http)->tenant->capabilities();

        self::assertSame('GET', $http->lastRequest()->getMethod());
        self::assertSame(self::BASE_URL . '/v1/tenant/capabilities', $this->url($http->lastRequest()));
    }

    public function testCouponValidatePostsFixedBody(): void
    {
        $http = (new MockHttpClient())->stage(200, ['valid' => true]);
        $this->makeClient($http)->coupons->validate([
            'code' => 'SAVE10',
            'price_id' => 'price_1',
            'amount_cents' => 999,
        ]);

        $req = $http->lastRequest();
        self::assertSame('POST', $req->getMethod());
        self::assertSame(self::BASE_URL . '/v1/coupons/validate', $this->url($req));
        $body = $this->bodyArray($req);
        self::assertSame('SAVE10', $body['code']);
        self::assertSame('price_1', $body['price_id']);
        self::assertSame(999, $body['amount_cents']);
    }

    public function testPurgeDefaultsConfirmedTrue(): void
    {
        $http = (new MockHttpClient())->stage(200, ['purged' => true]);
        $this->makeClient($http)->customers->purge('cus_1');

        $req = $http->lastRequest();
        self::assertSame(self::BASE_URL . '/v1/customers/cus_1/purge', $this->url($req));
        self::assertTrue($this->bodyArray($req)['confirmed']);
    }

    public function testSubscriptionReactivatePath(): void
    {
        $http = (new MockHttpClient())->stage(200, ['id' => 'sub_1', 'status' => 'active']);
        $this->makeClient($http)->subscriptions->reactivate('sub_1');

        self::assertSame(self::BASE_URL . '/v1/subscriptions/sub_1/reactivate', $this->url($http->lastRequest()));
    }

    public function testSubscriptionUpdateSendsTargetPriceId(): void
    {
        $http = (new MockHttpClient())->stage(200, ['id' => 'sub_1']);
        $this->makeClient($http)->subscriptions->update('sub_1', 'price_new');

        $req = $http->lastRequest();
        self::assertSame(self::BASE_URL . '/v1/subscriptions/sub_1/update', $this->url($req));
        self::assertSame('price_new', $this->bodyArray($req)['target_price_id']);
    }

    public function testBillingPortalSessionCreatePath(): void
    {
        $http = (new MockHttpClient())->stage(200, ['url' => 'https://portal.billkit.eu/x']);
        $this->makeClient($http)->billingPortalSessions->create([
            'subscription_id' => 'sub_1',
            'return_url' => 'https://app.example.com/back',
        ]);

        $req = $http->lastRequest();
        self::assertSame(self::BASE_URL . '/v1/billing_portal/sessions', $this->url($req));
        $body = $this->bodyArray($req);
        self::assertSame('sub_1', $body['subscription_id']);
        self::assertSame('https://app.example.com/back', $body['return_url']);
    }

    public function testWebhookDeliveryRedeliverPath(): void
    {
        $http = (new MockHttpClient())->stage(200, ['status' => 'pending']);
        $this->makeClient($http)->webhookEndpoints->redeliver('we_1', 'wde_1');

        self::assertSame(
            self::BASE_URL . '/v1/webhook_endpoints/we_1/deliveries/wde_1/redeliver',
            $this->url($http->lastRequest()),
        );
    }

    public function testWebhookRetrieveDeliveryPath(): void
    {
        $http = (new MockHttpClient())->stage(200, ['id' => 'wde_1']);
        $this->makeClient($http)->webhookEndpoints->retrieveDelivery('we_1', 'wde_1');

        $req = $http->lastRequest();
        self::assertSame('GET', $req->getMethod());
        self::assertSame(self::BASE_URL . '/v1/webhook_endpoints/we_1/deliveries/wde_1', $this->url($req));
    }

    public function testTaxRateCreateAndRetrieve(): void
    {
        $http = (new MockHttpClient())
            ->stage(200, ['id' => 'txr_1'])
            ->stage(200, ['id' => 'txr_1']);
        $client = $this->makeClient($http);

        $client->taxRates->create(['country_code' => 'NL', 'rate_basis_points' => 2100]);
        $client->taxRates->retrieve('txr_1');

        self::assertSame(self::BASE_URL . '/v1/tax_rates', $this->url($http->requests[0]));
        self::assertSame(self::BASE_URL . '/v1/tax_rates/txr_1', $this->url($http->requests[1]));
    }

    public function testOneShotPaymentCreatePostsBodyAndPreservesZeroRefundWindow(): void
    {
        $http = (new MockHttpClient())->stage(200, [
            'object' => 'one_shot_payment',
            'id' => 'osp_1',
            'redirect_url' => 'https://pay.mollie.com/osp_1',
        ]);
        $this->makeClient($http)->oneShotPayments->create([
            'customer_id' => 'cus_1',
            'amount_cents' => 1999,
            'currency' => 'EUR',
            'method' => 'ideal',
            'success_url' => 'https://app.example.com/done',
            'cancel_url' => null,
            'refund_window_days' => 0,
        ]);

        $req = $http->lastRequest();
        self::assertSame('POST', $req->getMethod());
        self::assertSame(self::BASE_URL . '/v1/checkout/one_shot', $this->url($req));
        $body = $this->bodyArray($req);
        self::assertSame('cus_1', $body['customer_id']);
        self::assertSame(1999, $body['amount_cents']);
        self::assertSame('EUR', $body['currency']);
        self::assertSame('ideal', $body['method']);
        self::assertSame('https://app.example.com/done', $body['success_url']);
        self::assertSame(0, $body['refund_window_days']);
        self::assertArrayNotHasKey('cancel_url', $body);
    }

    public function testOneShotPaymentRetrieveIsGet(): void
    {
        $http = (new MockHttpClient())->stage(200, ['object' => 'one_shot_payment', 'id' => 'osp_1']);
        $this->makeClient($http)->oneShotPayments->retrieve('osp_1');

        $req = $http->lastRequest();
        self::assertSame('GET', $req->getMethod());
        self::assertSame(self::BASE_URL . '/v1/checkout/one_shot/osp_1', $this->url($req));
    }

    public function testRefundAgainstOneShotPayment(): void
    {
        $http = (new MockHttpClient())->stage(200, ['id' => 're_1']);
        $this->makeClient($http)->refunds->create(['one_shot_payment_id' => 'osp_1']);

        $req = $http->lastRequest();
        self::assertSame('POST', $req->getMethod());
        self::assertSame(self::BASE_URL . '/v1/refunds', $this->url($req));
        self::assertSame('osp_1', $this->bodyArray($req)['one_shot_payment_id']);
    }

    public function testDisputeRetrieveIsGet(): void
    {
        $http = (new MockHttpClient())->stage(200, ['object' => 'dispute', 'id' => 'dp_1', 'status' => 'open']);
        $dispute = $this->makeClient($http)->disputes->retrieve('dp_1');

        $req = $http->lastRequest();
        self::assertSame('GET', $req->getMethod());
        self::assertSame(self::BASE_URL . '/v1/disputes/dp_1', $this->url($req));
        self::assertSame('open', $dispute['status']);
    }

    public function testDisputeListIsGet(): void
    {
        $http = (new MockHttpClient())->stage(200, ['object' => 'list', 'data' => [], 'has_more' => false]);
        $this->makeClient($http)->disputes->all(['limit' => 5]);

        $req = $http->lastRequest();
        self::assertSame('GET', $req->getMethod());
        self::assertStringStartsWith(self::BASE_URL . '/v1/disputes', $this->url($req));
    }

    public function testDeleteUsesDeleteVerbAndReturnsAMarker(): void
    {
        // The customer leaves the API, so the body is a deletion marker
        // rather than a row nobody can fetch again.
        $http = (new MockHttpClient())->stage(200, [
            'id' => 'cus_1',
            'object' => 'customer',
            'deleted' => true,
        ]);
        $deleted = $this->makeClient($http)->customers->delete('cus_1');

        self::assertSame('DELETE', $http->lastRequest()->getMethod());
        self::assertSame(self::BASE_URL . '/v1/customers/cus_1', $this->url($http->lastRequest()));
        self::assertTrue($deleted['deleted']);
    }

    /**
     * The catalogue is retired through its update route. Each of those
     * used to carry a `delete()`. None of them deleted anything: every
     * one of those rows stays readable afterwards, which is why they
     * have to. Customers and webhook endpoints really do leave the API.
     */
    public function testDeleteIsOnlyExposedWhereTheObjectLeaves(): void
    {
        $client = $this->makeClient(new MockHttpClient());
        foreach (['prices', 'products', 'coupons', 'taxRates'] as $name) {
            self::assertFalse(
                method_exists($client->{$name}, 'delete'),
                "{$name}->delete() should not exist",
            );
            self::assertTrue(method_exists($client->{$name}, 'update'));
        }
        self::assertTrue(method_exists($client->customers, 'delete'));
        // Configuration, not a record of money: a mistyped URL is removed.
        // Disabling stays beside it as the reversible act.
        self::assertTrue(method_exists($client->webhookEndpoints, 'delete'));
        self::assertTrue(method_exists($client->webhookEndpoints, 'update'));
    }

    public function testDeleteWebhookEndpointSendsDeleteAndLiftsIdempotencyKey(): void
    {
        $http = (new MockHttpClient())->stage(200, [
            'id' => 'we_1',
            'object' => 'webhook_endpoint',
            'deleted' => true,
        ]);
        $gone = $this->makeClient($http)->webhookEndpoints->delete('we_1', 'drop-1');

        $req = $http->lastRequest();
        self::assertSame('DELETE', $req->getMethod());
        self::assertSame(self::BASE_URL . '/v1/webhook_endpoints/we_1', $this->url($req));
        self::assertSame('drop-1', $req->getHeaderLine('Idempotency-Key'));
        self::assertTrue($gone['deleted']);
    }

    public function testCreateUsageRecordPostsBodyAndLiftsIdempotencyKey(): void
    {
        $http = (new MockHttpClient())->stage(201, [
            'id' => 'ur_1',
            'object' => 'usage_record',
            'subscription_id' => 'sub_1',
            'quantity' => 42,
            'invoice_id' => null,
        ]);
        $this->makeClient($http)->subscriptions->createUsageRecord('sub_1', [
            'quantity' => 42,
            'occurred_at' => 1700000000,
            'metadata' => ['source' => 'unit'],
            'idempotency_key' => 'usage-1',
        ]);

        $req = $http->lastRequest();
        self::assertSame('POST', $req->getMethod());
        self::assertSame(self::BASE_URL . '/v1/subscriptions/sub_1/usage_records', $this->url($req));
        $body = $this->bodyArray($req);
        self::assertSame(42, $body['quantity']);
        self::assertSame(1700000000, $body['occurred_at']);
        self::assertSame(['source' => 'unit'], $body['metadata']);
        // The reserved key is lifted into the header, never sent in the body.
        self::assertArrayNotHasKey('idempotency_key', $body);
        self::assertSame('usage-1', $req->getHeaderLine('Idempotency-Key'));
    }

    public function testCreateUsageRecordMinimalBodyOmitsOptionals(): void
    {
        $http = (new MockHttpClient())->stage(201, ['id' => 'ur_1', 'object' => 'usage_record']);
        $this->makeClient($http)->subscriptions->createUsageRecord('sub_1', ['quantity' => 1]);

        $body = $this->bodyArray($http->lastRequest());
        self::assertSame(['quantity' => 1], $body);
    }

    public function testListUsageRecordsForwardsInvoiceIdFilter(): void
    {
        $http = (new MockHttpClient())->stage(200, ['object' => 'list', 'data' => [], 'has_more' => false]);
        $this->makeClient($http)->subscriptions->listUsageRecords('sub_1', [
            'invoice_id' => 'pending',
            'limit' => 25,
        ]);

        $req = $http->lastRequest();
        self::assertSame('GET', $req->getMethod());
        self::assertStringStartsWith(
            self::BASE_URL . '/v1/subscriptions/sub_1/usage_records',
            $this->url($req),
        );
        parse_str($req->getUri()->getQuery(), $query);
        self::assertSame('pending', $query['invoice_id']);
        self::assertSame('25', $query['limit']);
    }

    public function testAutoPagingUsageRecordsCarriesInvoiceIdOnEveryPage(): void
    {
        // The filter used to be dropped, so `'pending'` silently walked
        // every usage record ever reported — the opposite of the question
        // the caller asked, and with no error to notice.
        $http = (new MockHttpClient())
            ->stage(200, [
                'object' => 'list',
                'data' => [['id' => 'ur_1'], ['id' => 'ur_2']],
                'has_more' => true,
            ])
            ->stage(200, [
                'object' => 'list',
                'data' => [['id' => 'ur_3']],
                'has_more' => false,
            ]);

        $ids = [];
        foreach (
            $this->makeClient($http)->subscriptions->autoPagingIteratorUsageRecords(
                'sub_1',
                pageSize: 2,
                invoiceId: 'pending',
            ) as $record
        ) {
            $ids[] = $record['id'];
        }

        self::assertSame(['ur_1', 'ur_2', 'ur_3'], $ids);
        self::assertCount(2, $http->requests);
        foreach ($http->requests as $req) {
            parse_str($req->getUri()->getQuery(), $query);
            self::assertSame('pending', $query['invoice_id']);
            self::assertSame('2', $query['limit']);
        }
        // The second page still advances the cursor.
        parse_str($http->requests[1]->getUri()->getQuery(), $page2);
        self::assertSame('ur_2', $page2['starting_after']);
    }

    public function testAutoPagingUsageRecordsOmitsTheFilterWhenNotGiven(): void
    {
        $http = (new MockHttpClient())->stage(200, [
            'object' => 'list',
            'data' => [],
            'has_more' => false,
        ]);

        iterator_to_array(
            $this->makeClient($http)->subscriptions->autoPagingIteratorUsageRecords('sub_1'),
        );

        parse_str($http->lastRequest()->getUri()->getQuery(), $query);
        self::assertArrayNotHasKey('invoice_id', $query);
    }

    public function testPriceCreateCarriesUsageType(): void
    {
        $http = (new MockHttpClient())->stage(200, ['id' => 'price_1', 'usage_type' => 'metered']);
        $this->makeClient($http)->prices->create([
            'product_id' => 'prod_api',
            'amount_cents' => 5,
            'currency' => 'EUR',
            'interval' => 'month',
            'usage_type' => 'metered',
        ]);

        self::assertSame('metered', $this->bodyArray($http->lastRequest())['usage_type']);
    }

    public function testPriceUpdateArchivesWithThePostVerb(): void
    {
        // Archiving leaves the price readable, so the row comes back in
        // the response and the caller reads `active` off it. It was a
        // DELETE until the verb was corrected.
        $http = (new MockHttpClient())->stage(200, ['id' => 'price_1', 'active' => false]);
        $archived = $this->makeClient($http)->prices->update('price_1', ['active' => false]);

        $req = $http->lastRequest();
        self::assertSame('POST', $req->getMethod());
        self::assertSame(self::BASE_URL . '/v1/prices/price_1', $this->url($req));
        self::assertSame(['active' => false], $this->bodyArray($req));
        self::assertStringStartsWith('sdk-', $req->getHeaderLine('Idempotency-Key'));
        self::assertFalse($archived['active']);
    }

    /**
     * `active` moves both ways. It decides what new checkouts may buy and
     * nothing else, so neither direction can change what a past charge was
     * made under, which is what price immutability actually protects.
     */
    public function testPriceUpdatePutsAPriceBackOnSale(): void
    {
        $http = (new MockHttpClient())->stage(200, ['id' => 'price_1', 'active' => true]);
        $back = $this->makeClient($http)->prices->update('price_1', ['active' => true]);

        self::assertSame(['active' => true], $this->bodyArray($http->lastRequest()));
        self::assertTrue($back['active']);
    }

    public function testPriceUpdateHonoursAnExplicitIdempotencyKey(): void
    {
        $http = (new MockHttpClient())->stage(200, ['id' => 'price_1', 'active' => false]);
        $this->makeClient($http)->prices->update('price_1', [
            'active' => false,
            'idempotency_key' => 'archive-1',
        ]);

        self::assertSame('archive-1', $http->lastRequest()->getHeaderLine('Idempotency-Key'));
    }

    public function testSubscriptionListForwardsRenewalStateFilter(): void
    {
        // `renewal_state=paused` is the only way to find paused rows:
        // pausing leaves `status` at `active`, and `status=paused` is
        // rejected by the API.
        $http = (new MockHttpClient())->stage(200, ['object' => 'list', 'data' => [], 'has_more' => false]);
        $this->makeClient($http)->subscriptions->all(['renewal_state' => 'paused']);

        $req = $http->lastRequest();
        self::assertSame('GET', $req->getMethod());
        parse_str($req->getUri()->getQuery(), $query);
        self::assertSame('paused', $query['renewal_state']);
        self::assertArrayNotHasKey('status', $query);
    }

    public function testSubscriptionListForwardsCustomerAndCsvStatus(): void
    {
        $http = (new MockHttpClient())->stage(200, ['object' => 'list', 'data' => [], 'has_more' => false]);
        $this->makeClient($http)->subscriptions->all([
            'customer_id' => 'cus_1',
            'status' => 'active,past_due',
            'limit' => 25,
        ]);

        parse_str($http->lastRequest()->getUri()->getQuery(), $query);
        self::assertSame('cus_1', $query['customer_id']);
        self::assertSame('active,past_due', $query['status']);
        self::assertSame('25', $query['limit']);
    }

    public function testAutoPagingSubscriptionsCarriesTheFilterOnEveryPage(): void
    {
        $http = (new MockHttpClient())
            ->stage(200, [
                'object' => 'list',
                'data' => [['id' => 'sub_1']],
                'has_more' => true,
            ])
            ->stage(200, [
                'object' => 'list',
                'data' => [['id' => 'sub_2']],
                'has_more' => false,
            ]);

        $ids = [];
        foreach (
            $this->makeClient($http)->subscriptions->autoPagingIterator(
                pageSize: 1,
                filters: ['renewal_state' => 'paused'],
            ) as $sub
        ) {
            $ids[] = $sub['id'];
        }

        self::assertSame(['sub_1', 'sub_2'], $ids);
        self::assertCount(2, $http->requests);
        foreach ($http->requests as $req) {
            parse_str($req->getUri()->getQuery(), $query);
            self::assertSame('paused', $query['renewal_state']);
        }
        parse_str($http->requests[1]->getUri()->getQuery(), $page2);
        self::assertSame('sub_1', $page2['starting_after']);
    }

    // ─── Metered pricing: sub-cent rates, tiers, dedupe, summary ─────
    //
    // PHP has no decimal type, which makes it the SDK where the float
    // mistake is easiest to make, so the rate assertions look at the raw
    // JSON rather than the decoded body.

    public function testPriceCreateSendsUnitAmountDecimalAsAJsonString(): void
    {
        $http = (new MockHttpClient())->stage(200, ['id' => 'price_1', 'unit_amount_decimal' => '0.02']);
        $this->makeClient($http)->prices->create([
            'product_id' => 'prod_api',
            'currency' => 'EUR',
            'interval' => 'month',
            'usage_type' => 'metered',
            'unit_amount_decimal' => '0.02',
        ]);

        $req = $http->lastRequest();
        // Asserted on the raw bytes: the risk is exactly that the rate
        // travels as a JSON number, which a reader would parse into a
        // double that is not 0.02.
        self::assertStringContainsString('"unit_amount_decimal":"0.02"', (string) $req->getBody());
        $body = $this->bodyArray($req);
        self::assertSame('0.02', $body['unit_amount_decimal']);
        // A price priced by the decimal sends no integer amount at all.
        self::assertArrayNotHasKey('amount_cents', $body);
    }

    public function testPriceCreateRefusesAFloatRate(): void
    {
        $http = (new MockHttpClient())->stage(200, ['id' => 'price_1']);
        $client = $this->makeClient($http);

        try {
            $client->prices->create([
                'product_id' => 'prod_api',
                'currency' => 'EUR',
                'interval' => 'month',
                'usage_type' => 'metered',
                'unit_amount_decimal' => 0.0002,
            ]);
            self::fail('a float rate must not reach the wire');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('unit_amount_decimal', $e->getMessage());
            self::assertStringContainsString('float', $e->getMessage());
        }

        // Refused before any HTTP call, not after one.
        self::assertSame([], $http->requests);
    }

    public function testPriceCreateStringifiesAnIntegerRate(): void
    {
        // An int is exact, so it cannot corrupt anything. Only the float is
        // a lie, and the wire still has to carry a string.
        $http = (new MockHttpClient())->stage(200, ['id' => 'price_1']);
        $this->makeClient($http)->prices->create([
            'product_id' => 'prod_api',
            'currency' => 'EUR',
            'interval' => 'month',
            'usage_type' => 'metered',
            'unit_amount_decimal' => 1,
        ]);

        self::assertStringContainsString(
            '"unit_amount_decimal":"1"',
            (string) $http->lastRequest()->getBody(),
        );
    }

    public function testPriceCreateSendsATierTable(): void
    {
        $http = (new MockHttpClient())->stage(200, ['id' => 'price_1']);
        $this->makeClient($http)->prices->create([
            'product_id' => 'prod_api',
            'currency' => 'EUR',
            'interval' => 'month',
            'usage_type' => 'metered',
            'billing_scheme' => 'tiered',
            'tiers_mode' => 'graduated',
            'tiers' => [
                ['up_to' => 1000, 'unit_amount' => 1],
                ['up_to' => 'inf', 'unit_amount_decimal' => '0.5', 'flat_amount' => 500],
            ],
        ]);

        $body = $this->bodyArray($http->lastRequest());
        self::assertSame('tiered', $body['billing_scheme']);
        self::assertSame('graduated', $body['tiers_mode']);
        self::assertSame([
            ['up_to' => 1000, 'unit_amount' => 1],
            ['up_to' => 'inf', 'unit_amount_decimal' => '0.5', 'flat_amount' => 500],
        ], $body['tiers']);
    }

    public function testPriceCreateRefusesAFloatInsideATier(): void
    {
        $http = (new MockHttpClient())->stage(200, ['id' => 'price_1']);

        try {
            $this->makeClient($http)->prices->create([
                'product_id' => 'prod_api',
                'currency' => 'EUR',
                'interval' => 'month',
                'usage_type' => 'metered',
                'billing_scheme' => 'tiered',
                'tiers_mode' => 'graduated',
                'tiers' => [['up_to' => 'inf', 'unit_amount_decimal' => 0.5]],
            ]);
            self::fail('a float rate inside a tier must not reach the wire');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('tiers[0].unit_amount_decimal', $e->getMessage());
        }

        self::assertSame([], $http->requests);
    }

    public function testTierIntegerRateIsStringifiedOnTheWire(): void
    {
        // Same rule one level down: the band's rate reaches the API as a
        // string even when it was written as an exact integer.
        $http = (new MockHttpClient())->stage(200, ['id' => 'price_1']);
        $this->makeClient($http)->prices->create([
            'product_id' => 'prod_api',
            'currency' => 'EUR',
            'interval' => 'month',
            'usage_type' => 'metered',
            'billing_scheme' => 'tiered',
            'tiers_mode' => 'volume',
            'tiers' => [['up_to' => 'inf', 'unit_amount_decimal' => 1]],
        ]);

        self::assertStringContainsString(
            '"unit_amount_decimal":"1"',
            (string) $http->lastRequest()->getBody(),
        );
    }

    public function testPriceCreateCarriesRefundOnCancel(): void
    {
        $http = (new MockHttpClient())->stage(200, ['id' => 'price_1', 'refund_on_cancel' => 'prorated']);
        $this->makeClient($http)->prices->create([
            'product_id' => 'prod_1',
            'amount_cents' => 1499,
            'currency' => 'EUR',
            'interval' => 'month',
            'refund_on_cancel' => 'prorated',
        ]);

        self::assertSame('prorated', $this->bodyArray($http->lastRequest())['refund_on_cancel']);
    }

    public function testCreateUsageRecordCarriesTheIdentifier(): void
    {
        // The dedupe an Idempotency-Key cannot do: a job runner replaying
        // its own task sends a NEW request with a NEW key.
        $http = (new MockHttpClient())->stage(201, ['id' => 'ur_1', 'identifier' => 'job-42']);
        $this->makeClient($http)->subscriptions->createUsageRecord('sub_1', [
            'quantity' => 10,
            'identifier' => 'job-42',
        ]);

        self::assertSame(
            ['quantity' => 10, 'identifier' => 'job-42'],
            $this->bodyArray($http->lastRequest()),
        );
    }

    public function testRetrieveUsageSummaryIsGet(): void
    {
        $http = (new MockHttpClient())->stage(200, [
            'object' => 'usage_summary',
            'pending_quantity' => 3,
            'gross_cents' => 15,
            'will_charge' => false,
            'minimum_charge_cents' => 100,
        ]);
        $summary = $this->makeClient($http)->subscriptions->retrieveUsageSummary('sub_1');

        $req = $http->lastRequest();
        self::assertSame('GET', $req->getMethod());
        self::assertSame(self::BASE_URL . '/v1/subscriptions/sub_1/usage_summary', $this->url($req));
        // The point of the endpoint: EUR 0.15 of usage will not be charged
        // this cycle, and the caller can see that before quoting an amount.
        self::assertFalse($summary['will_charge']);
    }

    // ── 0.7.0 surface ────────────────────────────────────────────────

    /**
     * An id is caller data, and one carrying `/`, `?` or `#` must not be
     * able to rewrite the request onto another route: `#` truncates the
     * path, `?` turns the tail into a query string, and `/` walks
     * somewhere else entirely.
     */
    public function testPathIdsArePercentEncoded(): void
    {
        $http = (new MockHttpClient())->stage(200, ['id' => 'x']);
        $this->makeClient($http)->customers->retrieve('cus_1/../../v1/tenant/export?x=1#frag');

        $req = $http->lastRequest();
        self::assertSame(
            self::BASE_URL . '/v1/customers/cus_1%2F..%2F..%2Fv1%2Ftenant%2Fexport%3Fx%3D1%23frag',
            $this->url($req),
        );
        // The route the method names, not the one the id tried to reach.
        self::assertSame('/v1/customers/cus_1%2F..%2F..%2Fv1%2Ftenant%2Fexport%3Fx%3D1%23frag', $req->getUri()->getPath());
        self::assertSame('', $req->getUri()->getQuery());
        self::assertSame('', $req->getUri()->getFragment());
    }

    public function testEncodedIdStillLandsOnASubRoute(): void
    {
        $http = (new MockHttpClient())->stage(200, ['id' => 'sub_1']);
        $this->makeClient($http)->subscriptions->cancel('sub a/b');

        self::assertSame(
            self::BASE_URL . '/v1/subscriptions/sub%20a%2Fb/cancel',
            $this->url($http->lastRequest()),
        );
    }

    public function testExpandIsSentAsACommaJoinedList(): void
    {
        $http = (new MockHttpClient())->stage(200, ['object' => 'list', 'data' => [], 'has_more' => false]);
        $this->makeClient($http)->subscriptions->all(['expand' => ['customer', 'price']]);

        self::assertSame('expand=customer%2Cprice', $http->lastRequest()->getUri()->getQuery());
    }

    public function testExpandOnRetrieve(): void
    {
        $http = (new MockHttpClient())->stage(200, ['id' => 'inv_1']);
        $this->makeClient($http)->invoices->retrieve('inv_1', ['expand' => ['customer']]);

        $req = $http->lastRequest();
        self::assertSame(self::BASE_URL . '/v1/invoices/inv_1?expand=customer', $this->url($req));
    }

    /**
     * The one place a `null` in a params array is a value: clearing the
     * registration. Stripping it would send an empty body, which the API
     * reads as "leave it alone".
     */
    public function testSetVatNumberSendsAnExplicitNullToClear(): void
    {
        $http = (new MockHttpClient())->stage(200, ['id' => 'cus_1', 'vat_number' => null]);
        $this->makeClient($http)->customers->setVatNumber('cus_1', ['vat_number' => null]);

        $req = $http->lastRequest();
        self::assertSame(self::BASE_URL . '/v1/customers/cus_1/vat_number', $this->url($req));
        self::assertSame('{"vat_number":null}', (string) $req->getBody());
    }

    /**
     * A null is a value here, so an absent key cannot be read as one: a call
     * carrying only country_code would otherwise clear a registration the
     * caller never mentioned.
     */
    public function testSetVatNumberRefusesAnAbsentKey(): void
    {
        $http = new MockHttpClient();
        $this->expectException(\InvalidArgumentException::class);
        $this->makeClient($http)->customers->setVatNumber('cus_1', ['country_code' => 'NL']);
    }

    public function testSetVatNumberDropsANullCountryCode(): void
    {
        $http = (new MockHttpClient())->stage(200, ['id' => 'cus_1']);
        $this->makeClient($http)->customers->setVatNumber('cus_1', [
            'vat_number' => 'NL123456789B01',
            'country_code' => null,
        ]);

        self::assertSame(
            ['vat_number' => 'NL123456789B01'],
            $this->bodyArray($http->lastRequest()),
        );
    }

    public function testPriceUpdateCarriesEveryForwardLookingField(): void
    {
        $http = (new MockHttpClient())->stage(200, ['id' => 'price_1']);
        $this->makeClient($http)->prices->update('price_1', [
            'metadata' => ['tier' => 'pro'],
            'tax_behavior' => 'exclusive',
            'payment_methods' => ['creditcard', 'ideal'],
            'refund_on_cancel' => 'prorated',
            'refund_window_initial_days' => 14,
            'refund_window_renewal_days' => 0,
        ]);

        $body = $this->bodyArray($http->lastRequest());
        self::assertSame(['tier' => 'pro'], $body['metadata']);
        self::assertSame('exclusive', $body['tax_behavior']);
        self::assertSame(['creditcard', 'ideal'], $body['payment_methods']);
        self::assertSame('prorated', $body['refund_on_cancel']);
        self::assertSame(14, $body['refund_window_initial_days']);
        // 0 disables refunds for that charge type, so it must survive the
        // null-stripping that `false`/`0`/`''` are exempt from.
        self::assertSame(0, $body['refund_window_renewal_days']);
        self::assertArrayNotHasKey('active', $body);
    }

    public function testCheckoutSessionCarriesCountry(): void
    {
        $http = (new MockHttpClient())->stage(200, ['id' => 'cs_1']);
        $this->makeClient($http)->checkoutSessions->create([
            'price_id' => 'price_1',
            'success_url' => 'https://app.example.com/ok',
            'cancel_url' => 'https://app.example.com/no',
            'country' => 'NL',
        ]);

        self::assertSame('NL', $this->bodyArray($http->lastRequest())['country']);
    }

    public function testBillingPortalSessionCarriesDeliverEmail(): void
    {
        $http = (new MockHttpClient())->stage(200, ['url' => 'https://portal.billkit.eu/x']);
        $this->makeClient($http)->billingPortalSessions->create([
            'subscription_id' => 'sub_1',
            'return_url' => 'https://app.example.com/back',
            'deliver_email' => true,
        ]);

        $body = $this->bodyArray($http->lastRequest());
        self::assertTrue($body['deliver_email']);
        self::assertSame('sub_1', $body['subscription_id']);
    }

    public function testInvoiceSendEmailPostsAnEmptyBody(): void
    {
        $http = (new MockHttpClient())->stage(200, ['object' => 'invoice_email', 'sent' => true]);
        $this->makeClient($http)->invoices->sendEmail('inv_1');

        $req = $http->lastRequest();
        self::assertSame('POST', $req->getMethod());
        self::assertSame(self::BASE_URL . '/v1/invoices/inv_1/email', $this->url($req));
        self::assertSame('', (string) $req->getBody());
    }

    public function testPaymentProviderPayloadIsGet(): void
    {
        $http = (new MockHttpClient())->stage(200, ['available' => false, 'reason' => 'provider_unavailable']);
        $this->makeClient($http)->payments->retrieveProvider('pay_1');

        $req = $http->lastRequest();
        self::assertSame('GET', $req->getMethod());
        self::assertSame(self::BASE_URL . '/v1/payments/pay_1/provider', $this->url($req));
    }

    public function testEventTypesCatalogueIsGet(): void
    {
        $http = (new MockHttpClient())->stage(200, ['object' => 'list', 'data' => ['*']]);
        $this->makeClient($http)->webhookEndpoints->listEventTypes();

        $req = $http->lastRequest();
        self::assertSame('GET', $req->getMethod());
        self::assertSame(self::BASE_URL . '/v1/webhook_endpoints/event_types', $this->url($req));
    }

    public function testTenantBillingProfileRoundTrip(): void
    {
        $http = (new MockHttpClient())
            ->stage(200, ['object' => 'tenant_billing_profile', 'country_code' => 'NL'])
            ->stage(200, ['object' => 'tenant_billing_profile', 'country_code' => 'NL', 'vat_id' => null]);
        $client = $this->makeClient($http);

        $client->tenant->billingProfile();
        self::assertSame('GET', $http->lastRequest()->getMethod());
        self::assertSame(self::BASE_URL . '/v1/tenant/billing_profile', $this->url($http->lastRequest()));

        $client->tenant->setBillingProfile([
            'country_code' => 'NL',
            // Present-and-null clears the registration ...
            'vat_id' => null,
            'city' => 'Amsterdam',
            // ... while `postal_code` is absent, so it is left alone.
        ]);
        $req = $http->lastRequest();
        self::assertSame('POST', $req->getMethod());
        self::assertSame('{"country_code":"NL","vat_id":null,"city":"Amsterdam"}', (string) $req->getBody());
    }

    public function testProductUpdateDefaultPriceIdSetClearAndOmit(): void
    {
        $http = (new MockHttpClient())
            ->stage(200, ['id' => 'prod_1'])
            ->stage(200, ['id' => 'prod_1'])
            ->stage(200, ['id' => 'prod_1']);
        $client = $this->makeClient($http);

        $client->products->update('prod_1', ['default_price_id' => 'price_2', 'idempotency_key' => 'dp-1']);
        $req = $http->lastRequest();
        self::assertSame(self::BASE_URL . '/v1/products/prod_1', $this->url($req));
        self::assertSame('{"default_price_id":"price_2"}', (string) $req->getBody());
        self::assertSame('dp-1', $req->getHeaderLine('Idempotency-Key'));

        // Present-and-null is the clear, so it survives the null-stripping
        // every other key gets. ``description`` clears the same way; any
        // other null (``name`` here) is still stripped.
        $client->products->update('prod_1', ['default_price_id' => null, 'description' => null, 'name' => null]);
        self::assertSame(
            '{"default_price_id":null,"description":null}',
            (string) $http->lastRequest()->getBody(),
        );

        // Absent leaves the default alone: the key is not sent at all.
        $client->products->update('prod_1', ['name' => 'Pro']);
        self::assertSame('{"name":"Pro"}', (string) $http->lastRequest()->getBody());
    }

    /**
     * @return iterable<string, array{string, string, string, list<string>}>
     */
    public static function clearableFields(): iterable
    {
        // The last two values are another field that endpoint's (strict)
        // update schema accepts, used for the "absent is not sent" half.
        yield 'customer name' => ['customers', 'cus_1', '/v1/customers/cus_1', ['name'], 'email', 'a@example.com'];
        yield 'webhook endpoint description' => [
            'webhookEndpoints', 'we_1', '/v1/webhook_endpoints/we_1', ['description'], 'status', 'disabled',
        ];
        yield 'coupon cap and expiry' => [
            'coupons', 'co_1', '/v1/coupons/co_1', ['max_redemptions', 'redeem_by'], 'active', false,
        ];
        yield 'tax rate display name' => [
            'taxRates', 'txr_1', '/v1/tax_rates/txr_1', ['display_name'], 'rate_basis_points', 2100,
        ];
    }

    /**
     * @param list<string> $keys
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('clearableFields')]
    public function testUpdateSendsAnExplicitNullToClear(
        string $resource,
        string $id,
        string $path,
        array $keys,
        string $otherKey,
        mixed $otherValue,
    ): void {
        $http = (new MockHttpClient())->stage(200, ['id' => $id])->stage(200, ['id' => $id]);
        $client = $this->makeClient($http);

        $clear = array_fill_keys($keys, null);
        $client->{$resource}->update($id, $clear + [$otherKey => null]);
        $req = $http->lastRequest();
        self::assertSame(self::BASE_URL . $path, $this->url($req));
        // Present-and-null reaches the API as a JSON null; the unrelated
        // null is still stripped.
        self::assertSame(json_encode($clear), (string) $req->getBody());

        // Absent leaves the stored value alone: the key is not sent.
        $client->{$resource}->update($id, [$otherKey => $otherValue]);
        self::assertSame(json_encode([$otherKey => $otherValue]), (string) $http->lastRequest()->getBody());
    }

    public function testOneShotListAndIteratorCarryTheFilters(): void
    {
        $http = (new MockHttpClient())
            ->stage(200, ['object' => 'list', 'data' => [], 'has_more' => false])
            ->stage(200, ['object' => 'list', 'data' => [['id' => 'osp_1']], 'has_more' => true])
            ->stage(200, ['object' => 'list', 'data' => [['id' => 'osp_2']], 'has_more' => false]);
        $client = $this->makeClient($http);

        $client->oneShotPayments->all(['customer_id' => 'cus_1', 'status' => 'paid', 'limit' => 5]);
        $req = $http->lastRequest();
        self::assertSame('/v1/checkout/one_shot', $req->getUri()->getPath());
        self::assertSame('customer_id=cus_1&status=paid&limit=5', $req->getUri()->getQuery());

        $ids = [];
        foreach ($client->oneShotPayments->autoPagingIterator(1, 'cus_1', 'paid') as $row) {
            $ids[] = $row['id'];
        }
        self::assertSame(['osp_1', 'osp_2'], $ids);
        parse_str($http->lastRequest()->getUri()->getQuery(), $q);
        self::assertSame(
            ['customer_id' => 'cus_1', 'status' => 'paid', 'limit' => '1', 'starting_after' => 'osp_1'],
            $q,
        );
    }

    public function testPaymentRetrieveExpandsRefundEligibility(): void
    {
        $http = (new MockHttpClient())->stage(200, [
            'id' => 'pay_1',
            'refund_eligibility' => ['object' => 'refund_eligibility', 'eligible' => true, 'amount_cents' => 999],
        ]);
        $payment = $this->makeClient($http)->payments->retrieve('pay_1', ['expand' => ['refund_eligibility']]);

        self::assertTrue($payment['refund_eligibility']['eligible']);
        self::assertSame('expand=refund_eligibility', $http->lastRequest()->getUri()->getQuery());
    }

    public function testProductRetrieveExpandsDefaultPrice(): void
    {
        $http = (new MockHttpClient())->stage(200, ['id' => 'prod_1', 'default_price' => ['id' => 'price_2']]);
        $product = $this->makeClient($http)->products->retrieve('prod_1', ['expand' => ['default_price']]);

        self::assertSame('price_2', $product['default_price']['id']);
        self::assertSame('expand=default_price', $http->lastRequest()->getUri()->getQuery());
    }

    public function testTenantExportUsesTheBinaryPath(): void
    {
        $http = (new MockHttpClient())->stage(200, '{"billkit_export_version":2}');
        $raw = $this->makeClient($http)->tenant->export();

        self::assertSame('{"billkit_export_version":2}', $raw);
        self::assertSame(self::BASE_URL . '/v1/tenant/export', $this->url($http->lastRequest()));
    }

    public function testApiKeyVerbs(): void
    {
        $http = (new MockHttpClient())
            ->stage(200, ['id' => 'ak_1', 'secret' => 'bk_test_xyz'])
            ->stage(200, ['id' => 'ak_1', 'prefix' => 'bk_test_xy'])
            ->stage(200, ['id' => 'ak_1', 'revoked_at' => 1])
            ->stage(200, ['object' => 'list', 'data' => [], 'has_more' => false]);
        $client = $this->makeClient($http);

        $client->apiKeys->create(['label' => 'ci', 'scopes' => ['products:read']]);
        self::assertSame(self::BASE_URL . '/v1/api_keys', $this->url($http->lastRequest()));
        self::assertSame(
            ['label' => 'ci', 'scopes' => ['products:read']],
            $this->bodyArray($http->lastRequest()),
        );

        $client->apiKeys->retrieve('ak_1');
        self::assertSame(self::BASE_URL . '/v1/api_keys/ak_1', $this->url($http->lastRequest()));

        $client->apiKeys->revoke('ak_1');
        $req = $http->lastRequest();
        self::assertSame('POST', $req->getMethod());
        self::assertSame(self::BASE_URL . '/v1/api_keys/ak_1/revoke', $this->url($req));

        $client->apiKeys->all(['limit' => 2]);
        self::assertSame('limit=2', $http->lastRequest()->getUri()->getQuery());
    }

    public function testListFiltersRideEveryPage(): void
    {
        $http = (new MockHttpClient())
            ->stage(200, ['object' => 'list', 'data' => [['id' => 'dp_1']], 'has_more' => true])
            ->stage(200, ['object' => 'list', 'data' => [['id' => 'dp_2']], 'has_more' => false]);

        $rows = iterator_to_array(
            $this->makeClient($http)->disputes->autoPagingIterator(pageSize: 1, status: 'open'),
        );

        self::assertCount(2, $rows);
        self::assertSame('status=open&limit=1', $http->requests[0]->getUri()->getQuery());
        // The filter has to ride the cursor page too, or the walk widens
        // after the first page.
        self::assertSame('status=open&limit=1&starting_after=dp_1', $http->requests[1]->getUri()->getQuery());
    }
}
