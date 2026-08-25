<?php

declare(strict_types=1);

namespace BillKit\Tests;

use BillKit\Exception\ApiConnectionException;
use BillKit\Exception\ServerException;
use BillKit\Tests\Support\MockHttpClient;
use BillKit\Tests\Support\MockNetworkException;

final class RetryTest extends BillKitTestCase
{
    public function testRetriesServerErrorThenSucceeds(): void
    {
        $http = (new MockHttpClient())
            ->stage(500, ['error' => ['type' => 'api_error']])
            ->stage(200, ['id' => 'cus_1']);
        $client = $this->makeClient($http);

        $customer = $client->customers->create(['email' => 'a@b.co']);

        self::assertSame('cus_1', $customer['id']);
        self::assertCount(2, $http->requests);
    }

    public function testRetriesConnectionErrorThenSucceeds(): void
    {
        $http = (new MockHttpClient())
            ->stageError(new MockNetworkException('connection reset'))
            ->stage(200, ['id' => 'cus_1']);
        $client = $this->makeClient($http);

        $customer = $client->customers->create(['email' => 'a@b.co']);

        self::assertSame('cus_1', $customer['id']);
        self::assertCount(2, $http->requests);
    }

    public function testGivesUpAfterMaxAttempts(): void
    {
        $http = (new MockHttpClient())
            ->stageError(new MockNetworkException('reset 1'))
            ->stageError(new MockNetworkException('reset 2'))
            ->stageError(new MockNetworkException('reset 3'));
        $client = $this->makeClient($http);

        $this->expectException(ApiConnectionException::class);
        try {
            $client->customers->create(['email' => 'a@b.co']);
        } finally {
            self::assertCount(3, $http->requests);
        }
    }

    public function testRateLimitWithRetryAfterIsRetried(): void
    {
        $http = (new MockHttpClient())
            ->stage(429, ['error' => ['type' => 'rate_limit_error']], ['Retry-After' => '0'])
            ->stage(200, ['id' => 'cus_1']);
        $client = $this->makeClient($http);

        $customer = $client->customers->create(['email' => 'a@b.co']);

        self::assertSame('cus_1', $customer['id']);
        self::assertCount(2, $http->requests);
    }

    public function testRetryReusesSameIdempotencyKey(): void
    {
        $http = (new MockHttpClient())
            ->stage(500, ['error' => ['type' => 'api_error']])
            ->stage(200, ['id' => 'cus_1']);
        $client = $this->makeClient($http);

        $client->customers->create(['email' => 'a@b.co']);

        self::assertCount(2, $http->requests);
        $first = $http->requests[0]->getHeaderLine('Idempotency-Key');
        $second = $http->requests[1]->getHeaderLine('Idempotency-Key');
        self::assertNotSame('', $first);
        self::assertSame($first, $second);
    }

    public function testNonRetryableServerErrorAfterExhaustion(): void
    {
        $http = (new MockHttpClient())
            ->stage(503, ['error' => ['type' => 'api_error']])
            ->stage(503, ['error' => ['type' => 'api_error']])
            ->stage(503, ['error' => ['type' => 'api_error']]);
        $client = $this->makeClient($http);

        $this->expectException(ServerException::class);
        $client->subscriptions->retrieve('sub_1');
    }
}
