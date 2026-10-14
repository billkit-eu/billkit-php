<?php

declare(strict_types=1);

namespace BillKit\Tests;

use BillKit\Exception\ApiConnectionException;
use BillKit\Exception\ApiException;
use BillKit\Exception\ApiTimeoutException;
use BillKit\Exception\AuthenticationException;
use BillKit\Exception\BillKitException;
use BillKit\Exception\ConflictException;
use BillKit\Exception\InvalidRequestException;
use BillKit\Exception\NotFoundException;
use BillKit\Exception\PaymentException;
use BillKit\Exception\PermissionDeniedException;
use BillKit\Exception\RateLimitException;
use BillKit\Exception\ServerException;
use BillKit\Exception\WebhookVerificationException;
use BillKit\Tests\Support\MockHttpClient;

final class ErrorsTest extends BillKitTestCase
{
    public function testNotFoundMapsToNotFound(): void
    {
        $http = (new MockHttpClient())->stage(404, ['error' => ['type' => 'invalid_request_error', 'message' => 'no such customer']]);
        $client = $this->makeClient($http);

        try {
            $client->customers->retrieve('cus_missing');
            self::fail('expected NotFoundException');
        } catch (NotFoundException $err) {
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

    public function testPaymentRequiredMapsToPayment(): void
    {
        // A refused charge and a suspended account are both 402. Neither is
        // the caller's request to change (InvalidRequestException said so
        // before), and neither is retried.
        foreach ([
            'payment_refused' => 'The provider refused the refund: insufficient balance.',
            'tenant_suspended' => 'This account is suspended.',
        ] as $code => $message) {
            $http = (new MockHttpClient())
                ->stage(402, ['error' => ['type' => 'payment_error', 'code' => $code, 'message' => $message]]);
            $client = $this->makeClient($http);

            try {
                $client->refunds->create(['payment_id' => 'pay_1']);
                self::fail('expected PaymentException');
            } catch (PaymentException $err) {
                self::assertNotInstanceOf(InvalidRequestException::class, $err);
                self::assertSame(402, $err->statusCode);
                self::assertSame('payment_error', $err->errorType);
                self::assertSame($code, $err->errorCode);
                self::assertSame($message, $err->getMessage());
            }
            self::assertCount(1, $http->requests, $code);
        }
    }

    public function testForbiddenMapsToPermissionDenied(): void
    {
        $http = (new MockHttpClient())->stage(403, ['error' => ['type' => 'permission_error', 'message' => 'nope']]);
        $client = $this->makeClient($http);

        $this->expectException(PermissionDeniedException::class);
        $client->customers->retrieve('cus_1');
    }

    public function testConflictMapsToConflict(): void
    {
        $http = (new MockHttpClient())->stage(409, ['error' => ['type' => 'conflict_error', 'message' => 'already exists']]);
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
            self::assertNull($err->reason);
            self::assertSame(400, $err->statusCode);
        }
    }

    public function testReasonRidesOnTheException(): void
    {
        $pending = ['error' => [
            'type' => 'conflict_error',
            'code' => 'operation_pending',
            'reason' => 'renewal_processing',
            'message' => 'A renewal payment is processing for this subscription.',
        ]];
        $http = (new MockHttpClient())->stage(409, $pending)->stage(409, $pending)->stage(409, $pending);
        $client = $this->makeClient($http);

        try {
            $client->subscriptions->cancel('sub_1');
            self::fail('expected ConflictException');
        } catch (ConflictException $err) {
            self::assertSame('operation_pending', $err->errorCode);
            self::assertSame('renewal_processing', $err->reason);
        }
    }

    public function testRateLimitExceptionCarriesReason(): void
    {
        $http = (new MockHttpClient())->stage(429, ['error' => [
            'type' => 'rate_limit_error',
            'reason' => 'some_reason',
        ]]);
        $client = $this->makeClient($http);

        try {
            $client->customers->create(['email' => 'a@b.co']);
            self::fail('expected RateLimitException');
        } catch (RateLimitException $err) {
            self::assertSame('some_reason', $err->reason);
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

    // ── the status, not the envelope `type`, picks the class ──────────
    //
    // An unmatched route is a 404 whose `type` is `invalid_request_error`
    // (`route_not_found`): mapping on `type` would throw the wrong class.
    // Before 1.0 the same miss read `{"type": "api_error", "code":
    // "unhandled"}` *with the original 4xx status*, and a proxy in front of
    // the API can still answer that way; mapping on `type` turned those into
    // `ServerException`, the class retry and alerting policies key on. Node
    // and python assert the same cases.

    public function testRouteNotFoundIsNotFoundNotInvalidRequest(): void
    {
        $http = (new MockHttpClient())->stage(404, ['error' => [
            'type' => 'invalid_request_error', 'code' => 'route_not_found', 'message' => 'Unrecognized request URL.',
        ]]);
        $client = $this->makeClient($http);

        try {
            $client->customers->retrieve('cus_1');
            self::fail('expected NotFoundException');
        } catch (NotFoundException $err) {
            self::assertNotInstanceOf(InvalidRequestException::class, $err);
            self::assertSame('invalid_request_error', $err->errorType);
            self::assertSame('route_not_found', $err->errorCode);
        }
    }

    public function testNotFoundFromAnUnmatchedRouteIsNotAServerException(): void
    {
        $http = (new MockHttpClient())->stage(404, ['error' => [
            'type' => 'api_error', 'code' => 'unhandled', 'message' => 'Not Found',
        ]]);
        $client = $this->makeClient($http);

        try {
            $client->customers->retrieve('cus_1');
            self::fail('expected NotFoundException');
        } catch (NotFoundException $err) {
            self::assertNotInstanceOf(ServerException::class, $err);
            // The envelope value is still carried verbatim; it just does
            // not choose the class.
            self::assertSame('api_error', $err->errorType);
            self::assertSame('unhandled', $err->errorCode);
        }
    }

    public function testMethodNotAllowedIsAnInvalidRequest(): void
    {
        $http = (new MockHttpClient())->stage(405, ['error' => [
            'type' => 'invalid_request_error', 'code' => 'method_not_allowed', 'message' => 'nope',
        ]]);
        $client = $this->makeClient($http);

        $this->expectException(InvalidRequestException::class);
        $client->customers->retrieve('cus_1');
    }

    /** The mirror image: the status wins in both directions. */
    public function testServerErrorCarryingA4xxShapedTypeIsStillAServerException(): void
    {
        $http = (new MockHttpClient())
            ->stage(500, ['error' => ['type' => 'invalid_request_error', 'message' => 'mislabelled']])
            ->stage(500, ['error' => ['type' => 'invalid_request_error', 'message' => 'mislabelled']])
            ->stage(500, ['error' => ['type' => 'invalid_request_error', 'message' => 'mislabelled']]);
        $client = $this->makeClient($http);

        $this->expectException(ServerException::class);
        $client->customers->retrieve('cus_1');
    }

    public function testEveryHttpErrorIsAnApiExceptionCarryingHeaders(): void
    {
        foreach ([400 => InvalidRequestException::class, 401 => AuthenticationException::class,
            402 => PaymentException::class, 403 => PermissionDeniedException::class, 404 => NotFoundException::class,
            409 => ConflictException::class, 422 => InvalidRequestException::class,
            429 => RateLimitException::class, 500 => ServerException::class] as $status => $class) {
            $http = (new MockHttpClient())->stage($status, ['error' => ['type' => 'x', 'message' => 'm']], [
                'X-Request-Id' => "req_{$status}",
            ]);
            try {
                $this->makeClient($http, $this->fastRetry(1))->customers->retrieve('cus_1');
                self::fail("expected {$class}");
            } catch (ApiException $err) {
                self::assertInstanceOf($class, $err);
                self::assertInstanceOf(BillKitException::class, $err);
                self::assertSame($status, $err->statusCode);
                self::assertSame("req_{$status}", $err->requestId);
                self::assertSame("req_{$status}", $err->headers['x-request-id']);
                self::assertSame(['error' => ['type' => 'x', 'message' => 'm']], $err->rawBody);
            }
        }
    }

    public function testConnectionAndWebhookFailuresAreBillKitExceptionsButNotApiExceptions(): void
    {
        self::assertTrue(is_subclass_of(ApiConnectionException::class, BillKitException::class));
        self::assertFalse(is_subclass_of(ApiConnectionException::class, ApiException::class));
        self::assertTrue(is_subclass_of(ApiTimeoutException::class, ApiConnectionException::class));
        self::assertTrue(is_subclass_of(WebhookVerificationException::class, BillKitException::class));
        self::assertFalse(is_subclass_of(WebhookVerificationException::class, ApiException::class));
    }

    public function testFactoryBuildsTheSubclassARealResponseWould(): void
    {
        $cases = [
            400 => InvalidRequestException::class,
            401 => AuthenticationException::class,
            402 => PaymentException::class,
            403 => PermissionDeniedException::class,
            404 => NotFoundException::class,
            409 => ConflictException::class,
            422 => InvalidRequestException::class,
            429 => RateLimitException::class,
            500 => ServerException::class,
            503 => ServerException::class,
        ];
        foreach ($cases as $status => $class) {
            self::assertInstanceOf($class, ApiException::factory($status), "HTTP {$status}");
        }

        $e = ApiException::factory(
            409,
            ['type' => 'invalid_request_error', 'code' => 'operation_pending', 'reason' => 'renewal_processing', 'message' => 'Try later', 'param' => 'id'],
            ['X-Request-Id' => 'req_1'],
        );
        self::assertInstanceOf(ConflictException::class, $e);
        self::assertSame(409, $e->statusCode);
        self::assertSame('operation_pending', $e->errorCode);
        self::assertSame('renewal_processing', $e->reason);
        self::assertSame('invalid_request_error', $e->errorType);
        self::assertSame('id', $e->param);
        self::assertSame('Try later', $e->getMessage());
        self::assertSame('req_1', $e->requestId);
        self::assertSame(['error' => ['type' => 'invalid_request_error', 'code' => 'operation_pending', 'reason' => 'renewal_processing', 'message' => 'Try later', 'param' => 'id']], $e->rawBody);

        $limited = ApiException::factory(429, [], ['Retry-After' => '2']);
        self::assertInstanceOf(RateLimitException::class, $limited);
        self::assertSame(2.0, $limited->retryAfter);
        self::assertSame('rate_limit_error', $limited->errorType);

        // With no envelope, errorType falls back to the API's own vocabulary,
        // the same values node and python fill in.
        self::assertSame('not_found_error', ApiException::factory(404)->errorType);
        self::assertSame('conflict_error', ApiException::factory(409)->errorType);
        self::assertSame('payment_error', ApiException::factory(402)->errorType);
        self::assertSame('invalid_request_error', ApiException::factory(400)->errorType);
    }

    public function testFactoryRefusesAStatusThatIsNotAnHttpError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ApiException::factory(200);
    }
}
