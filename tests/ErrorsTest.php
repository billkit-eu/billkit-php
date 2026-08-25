<?php

declare(strict_types=1);

namespace BillKit\Tests;

use BillKit\Exception\AuthenticationException;
use BillKit\Exception\ConflictException;
use BillKit\Exception\InvalidRequestException;
use BillKit\Exception\PermissionException;
use BillKit\Exception\RateLimitException;
use BillKit\Exception\ResourceMissingException;
use BillKit\Exception\ServerException;
use BillKit\Tests\Support\MockHttpClient;

final class ErrorsTest extends BillKitTestCase
{
    public function testNotFoundMapsToResourceMissing(): void
    {
        $http = (new MockHttpClient())->stage(404, ['error' => ['type' => 'invalid_request_error', 'message' => 'no such customer']]);
        $client = $this->makeClient($http);

        try {
            $client->customers->retrieve('cus_missing');
            self::fail('expected ResourceMissingException');
        } catch (ResourceMissingException $err) {
            self::assertSame(404, $err->statusCode);
            self::assertSame('no such customer', $err->getMessage());
        }
    }

    public function testUnauthorizedMapsToAuthentication(): void
    {
        $http = (new MockHttpClient())->stage(401, ['error' => ['type' => 'authentication_error', 'message' => 'bad key']]);
        $client = $this->makeClient($http);

        $this->expectException(AuthenticationException::class);
        $client->customers->retrieve('cus_1');
    }

    public function testForbiddenMapsToPermission(): void
    {
        $http = (new MockHttpClient())->stage(403, ['error' => ['type' => 'permission_error', 'message' => 'nope']]);
        $client = $this->makeClient($http);

        $this->expectException(PermissionException::class);
        $client->customers->retrieve('cus_1');
    }

    public function testConflictMapsToConflict(): void
    {
        $http = (new MockHttpClient())->stage(409, ['error' => ['type' => 'conflict', 'message' => 'already exists']]);
        $client = $this->makeClient($http);

        $this->expectException(ConflictException::class);
        $client->customers->create(['email' => 'a@b.co']);
    }

    public function testBadRequestPopulatesEnvelopeFields(): void
    {
        $http = (new MockHttpClient())->stage(400, ['error' => [
            'type' => 'invalid_request_error',
            'code' => 'parameter_invalid',
            'message' => 'amount_cents must be positive',
            'param' => 'amount_cents',
        ]]);
        $client = $this->makeClient($http);

        try {
            $client->prices->create(['product_id' => 'prod_1', 'amount_cents' => -1, 'currency' => 'EUR', 'interval' => 'month']);
            self::fail('expected InvalidRequestException');
        } catch (InvalidRequestException $err) {
            self::assertSame('invalid_request_error', $err->errorType);
            self::assertSame('parameter_invalid', $err->errorCode);
            self::assertSame('amount_cents', $err->param);
            self::assertSame(400, $err->statusCode);
        }
    }

    public function testRateLimitWithoutRetryAfterIsNotRetried(): void
    {
        $http = (new MockHttpClient())->stage(429, ['error' => ['type' => 'rate_limit_error', 'message' => 'slow down']]);
        $client = $this->makeClient($http);

        try {
            $client->customers->create(['email' => 'a@b.co']);
            self::fail('expected RateLimitException');
        } catch (RateLimitException $err) {
            self::assertNull($err->retryAfter);
            self::assertCount(1, $http->requests);
        }
    }

    public function testRateLimitCarriesRetryAfter(): void
    {
        // No further staged responses => must not be retried, so surfaced directly.
        $http = (new MockHttpClient())->stage(429, ['error' => ['type' => 'rate_limit_error']], ['Retry-After' => '120']);
        // Cap retry-after budget below 120s so it is surfaced, not retried.
        $client = $this->makeClient($http, new \BillKit\RetryPolicy(maxAttempts: 3, initialBackoffMs: 0, backoffMultiplier: 1.0, maxBackoffMs: 0, maxRetryAfterMs: 1000, jitter: 0.0));

        try {
            $client->customers->create(['email' => 'a@b.co']);
            self::fail('expected RateLimitException');
        } catch (RateLimitException $err) {
            self::assertSame(120.0, $err->retryAfter);
            self::assertCount(1, $http->requests);
        }
    }

    public function testFallsBackToStatusWhenNoErrorEnvelope(): void
    {
        // Bare 500 with no ``error`` envelope; must still map to ServerException
        // and synthesise a message.
        $http = (new MockHttpClient())
            ->stage(500, ['unexpected' => 'shape'])
            ->stage(500, ['unexpected' => 'shape'])
            ->stage(500, ['unexpected' => 'shape']);
        $client = $this->makeClient($http);

        try {
            $client->customers->retrieve('cus_1');
            self::fail('expected ServerException');
        } catch (ServerException $err) {
            self::assertSame(500, $err->statusCode);
            self::assertStringContainsString('HTTP 500', $err->getMessage());
        }
    }

    public function testRetryAfterHttpDateIsParsed(): void
    {
        // Retry-After as an HTTP-date ~5 min out; capped budget => surfaced,
        // and the parsed retryAfter lands near 300s.
        $future = gmdate('D, d M Y H:i:s', time() + 300) . ' GMT';
        $http = (new MockHttpClient())->stage(429, ['error' => ['type' => 'rate_limit_error']], ['Retry-After' => $future]);
        $client = $this->makeClient($http, new \BillKit\RetryPolicy(maxAttempts: 3, initialBackoffMs: 0, backoffMultiplier: 1.0, maxBackoffMs: 0, maxRetryAfterMs: 1000, jitter: 0.0));

        try {
            $client->customers->create(['email' => 'a@b.co']);
            self::fail('expected RateLimitException');
        } catch (RateLimitException $err) {
            self::assertNotNull($err->retryAfter);
            self::assertGreaterThan(250.0, $err->retryAfter);
            self::assertLessThanOrEqual(301.0, $err->retryAfter);
            self::assertCount(1, $http->requests);
        }
    }

    public function testServerErrorSurfacesAfterRetries(): void
    {
        $http = (new MockHttpClient())
            ->stage(500, ['error' => ['type' => 'api_error', 'message' => 'boom']])
            ->stage(500, ['error' => ['type' => 'api_error', 'message' => 'boom']])
            ->stage(500, ['error' => ['type' => 'api_error', 'message' => 'boom']]);
        $client = $this->makeClient($http);

        $this->expectException(ServerException::class);
        try {
            $client->customers->retrieve('cus_1');
        } finally {
            self::assertCount(3, $http->requests);
        }
    }
}
