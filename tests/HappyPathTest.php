<?php

declare(strict_types=1);

namespace BillKit\Tests;

use BillKit\Tests\Support\MockHttpClient;

final class HappyPathTest extends BillKitTestCase
{
    public function testCreateCustomerSendsAuthAndIdempotencyHeaders(): void
    {
        $http = (new MockHttpClient())->stage(200, ['id' => 'cus_1', 'email' => 'a@b.co']);
        $client = $this->makeClient($http);

        $customer = $client->customers->create(['email' => 'a@b.co', 'name' => 'Ada']);

        self::assertSame('cus_1', $customer['id']);
        self::assertCount(1, $http->requests);
        $req = $http->lastRequest();
        self::assertSame('POST', $req->getMethod());
        self::assertSame(self::BASE_URL . '/v1/customers', $this->url($req));
        self::assertSame('Bearer sk_test_unit', $req->getHeaderLine('Authorization'));
        self::assertStringStartsWith('sdk-', $req->getHeaderLine('Idempotency-Key'));
        self::assertSame('application/json', $req->getHeaderLine('Content-Type'));
        self::assertSame('billkit-php/' . \BillKit\Version::VERSION, $req->getHeaderLine('User-Agent'));
    }

    public function testGetHasNoIdempotencyKey(): void
    {
        $http = (new MockHttpClient())->stage(200, ['id' => 'sub_1', 'status' => 'active']);
        $client = $this->makeClient($http);

        $client->subscriptions->retrieve('sub_1');

        self::assertSame('', $http->lastRequest()->getHeaderLine('Idempotency-Key'));
        self::assertSame('GET', $http->lastRequest()->getMethod());
    }

    public function testListPassesPaginationQueryString(): void
    {
        $http = (new MockHttpClient())->stage(200, ['object' => 'list', 'data' => [], 'has_more' => false]);
        $client = $this->makeClient($http);

        $client->customers->all(['limit' => 25, 'starting_after' => 'cus_x']);

        $query = (string) $http->lastRequest()->getUri()->getQuery();
        self::assertStringContainsString('limit=25', $query);
        self::assertStringContainsString('starting_after=cus_x', $query);
    }

    public function testCreateAndUpdateProducts(): void
    {
        $http = (new MockHttpClient())
            ->stage(200, ['id' => 'prod_1', 'name' => 'Pro'])
            ->stage(200, ['id' => 'prod_1', 'active' => false]);
        $client = $this->makeClient($http);

        $product = $client->products->create([
            'name' => 'Pro',
            'description' => 'Hosted billing for SaaS',
            'marketing_features' => ['Checkout', 'Subscriptions'],
        ]);
        $archived = $client->products->update('prod_1', ['active' => false]);

        self::assertSame('prod_1', $product['id']);
        self::assertFalse($archived['active']);
        self::assertSame(self::BASE_URL . '/v1/products', $this->url($http->requests[0]));
        self::assertCount(2, $this->bodyArray($http->requests[0])['marketing_features']);
        self::assertFalse($this->bodyArray($http->requests[1])['active']);
    }

    public function testCreatePriceCarriesTrialAndPaymentMethods(): void
    {
        $http = (new MockHttpClient())->stage(200, ['id' => 'price_1', 'product_id' => 'prod_1']);
        $client = $this->makeClient($http);

        $price = $client->prices->create([
            'product_id' => 'prod_1',
            'amount_cents' => 999,
            'currency' => 'EUR',
            'interval' => 'month',
            'trial_days' => 14,
            'payment_methods' => ['creditcard', 'directdebit'],
        ]);

        $body = $this->bodyArray($http->lastRequest());
        self::assertSame('price_1', $price['id']);
        self::assertSame('prod_1', $body['product_id']);
        self::assertSame(14, $body['trial_days']);
        self::assertSame(['creditcard', 'directdebit'], $body['payment_methods']);
    }

    public function testCancelUsesPostAndIdempotency(): void
    {
        $http = (new MockHttpClient())->stage(200, ['id' => 'sub_1', 'status' => 'canceled']);
        $client = $this->makeClient($http);

        $sub = $client->subscriptions->cancel('sub_1');

        self::assertSame('canceled', $sub['status']);
        self::assertSame('POST', $http->lastRequest()->getMethod());
        self::assertSame(self::BASE_URL . '/v1/subscriptions/sub_1/cancel', $this->url($http->lastRequest()));
        self::assertStringStartsWith('sdk-', $http->lastRequest()->getHeaderLine('Idempotency-Key'));
    }

    public function testCreateRefundDropsNullFields(): void
    {
        $http = (new MockHttpClient())->stage(200, ['id' => 're_1']);
        $client = $this->makeClient($http);

        $client->refunds->create([
            'subscription_id' => 'sub_1',
            'payment_id' => null,
            'reason' => 'user_requested',
        ]);

        $body = $this->bodyArray($http->lastRequest());
        self::assertSame('sub_1', $body['subscription_id']);
        self::assertSame('user_requested', $body['reason']);
        self::assertArrayNotHasKey('payment_id', $body);
    }

    public function testRespectsCallerSuppliedIdempotencyKey(): void
    {
        $http = (new MockHttpClient())->stage(200, ['id' => 'cus_1']);
        $client = $this->makeClient($http);

        $client->customers->create(['email' => 'a@b.co', 'idempotency_key' => 'my-key']);

        self::assertSame('my-key', $http->lastRequest()->getHeaderLine('Idempotency-Key'));
        self::assertArrayNotHasKey('idempotency_key', $this->bodyArray($http->lastRequest()));
    }

    public function testPreservesFalseAndZeroInBody(): void
    {
        $http = (new MockHttpClient())->stage(200, ['id' => 'price_1']);
        $client = $this->makeClient($http);

        $client->prices->create([
            'product_id' => 'prod_1',
            'amount_cents' => 0,
            'currency' => 'EUR',
            'interval' => 'month',
            'refund_window_initial_days' => 0,
        ]);

        $body = $this->bodyArray($http->lastRequest());
        self::assertSame(0, $body['amount_cents']);
        self::assertSame(0, $body['refund_window_initial_days']);
    }
}
