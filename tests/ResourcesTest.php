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

    public function testDeleteUsesDeleteVerb(): void
    {
        $http = (new MockHttpClient())->stage(200, ['deleted' => true]);
        $this->makeClient($http)->customers->delete('cus_1');

        self::assertSame('DELETE', $http->lastRequest()->getMethod());
        self::assertSame(self::BASE_URL . '/v1/customers/cus_1', $this->url($http->lastRequest()));
    }
}
