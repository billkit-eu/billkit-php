<?php

declare(strict_types=1);

namespace BillKit\Tests;

use BillKit\Exception\ApiConnectionException;
use BillKit\Exception\ApiTimeoutException;
use BillKit\Exception\ServerException;
use BillKit\Tests\Support\MockHttpClient;
use BillKit\Tests\Support\MockNetworkException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The `$opts` argument every resource method ends with.
 */
final class RequestOptionsTest extends BillKitTestCase
{
    public function testIdempotencyKeyOptionIsSentAsTheHeaderOnEveryAttempt(): void
    {
        $http = (new MockHttpClient())
            ->stage(502, ['error' => ['type' => 'api_error']])
            ->stage(200, ['id' => 'sub_1', 'object' => 'subscription']);

        $this->makeClient($http)->subscriptions->cancel('sub_1', [], ['idempotency_key' => 'cancel-sub_1']);

        self::assertSame('cancel-sub_1', $http->requests[0]->getHeaderLine('Idempotency-Key'));
        self::assertSame('cancel-sub_1', $http->requests[1]->getHeaderLine('Idempotency-Key'));
        // A lifecycle verb with no params still sends no body at all.
        self::assertSame('', (string) $http->requests[0]->getBody());
    }

    public function testAGeneratedKeyIsMintedOncePerCallNotPerAttempt(): void
    {
        $http = (new MockHttpClient())
            ->stage(503, ['error' => ['type' => 'api_error']])
            ->stage(503, ['error' => ['type' => 'api_error']])
            ->stage(200, ['id' => 're_1', 'object' => 'refund'])
            ->stage(200, ['id' => 're_2', 'object' => 'refund']);
        $client = $this->makeClient($http);

        $client->refunds->create(['payment_id' => 'pay_1']);
        $client->refunds->create(['payment_id' => 'pay_2']);

        $keys = array_map(static fn ($r): string => $r->getHeaderLine('Idempotency-Key'), $http->requests);
        self::assertSame($keys[0], $keys[1]);
        self::assertSame($keys[0], $keys[2]);
        // The next logical call gets its own key.
        self::assertNotSame($keys[0], $keys[3]);
    }

    public function testMaxRetriesZeroSendsOnce(): void
    {
        $http = (new MockHttpClient())
            ->stage(502, ['error' => ['type' => 'api_error']])
            ->stage(200, ['id' => 'cus_1']);

        try {
            $this->makeClient($http, $this->fastRetry(4))->customers->retrieve('cus_1', [], ['max_retries' => 0]);
            self::fail('expected the 502 to surface');
        } catch (ServerException) {
        }
        self::assertCount(1, $http->requests);
    }

    public function testMaxRetriesRaisesTheBudgetForOneCall(): void
    {
        $http = (new MockHttpClient())
            ->stage(502, ['error' => ['type' => 'api_error']])
            ->stage(502, ['error' => ['type' => 'api_error']])
            ->stage(502, ['error' => ['type' => 'api_error']])
            ->stage(200, ['id' => 'cus_1', 'object' => 'customer']);

        $customer = $this->makeClient($http, $this->fastRetry(1))->customers->retrieve('cus_1', [], ['max_retries' => 3]);

        self::assertSame('cus_1', $customer->id);
        self::assertCount(4, $http->requests);
    }

    public function testHeadersOptionAddsHeaders(): void
    {
        $http = (new MockHttpClient())->stage(200, ['id' => 'cus_1']);

        $this->makeClient($http)->customers->retrieve('cus_1', [], ['headers' => ['X-Trace-Id' => 'abc']]);

        self::assertSame('abc', $http->lastRequest()->getHeaderLine('X-Trace-Id'));
        self::assertSame('Bearer bk_test_unit', $http->lastRequest()->getHeaderLine('Authorization'));
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function badOptions(): iterable
    {
        yield 'misspelt key' => [['idempotencyKey' => 'k'], "unknown request option 'idempotencyKey'"];
        yield 'non-string key' => [['idempotency_key' => 42], 'idempotency_key option must be a string'];
        yield 'timeout as a string' => [['timeout' => '30'], 'number of seconds greater than 0'];
        yield 'zero timeout' => [['timeout' => 0], 'number of seconds greater than 0'];
        yield 'negative timeout' => [['timeout' => -1.5], 'number of seconds greater than 0'];
        yield 'NAN timeout' => [['timeout' => NAN], 'number of seconds greater than 0'];
        yield 'millisecond timeout' => [['timeout' => 5000], 'over the 600-second maximum'];
        yield 'infinite timeout' => [['timeout' => INF], 'over the 600-second maximum'];
        yield 'negative retries' => [['max_retries' => -1], 'max_retries option'];
        yield 'header list' => [['headers' => ['X-A']], 'headers option'];
        yield 'authorization header' => [['headers' => ['Authorization' => 'Bearer x']], 'set by the SDK'];
        yield 'idempotency header' => [['headers' => ['idempotency-key' => 'x']], 'pass the idempotency_key option'];
        // The bundled curl transport writes header lines verbatim, so any of
        // these would inject a header (or end the header block) of the
        // caller's choosing.
        yield 'CRLF in a header value' => [['headers' => ['X-A' => "v\r\nX-Injected: 1"]], 'cannot appear in an HTTP header'];
        yield 'bare LF in a header value' => [['headers' => ['X-A' => "v\nX-Injected: 1"]], 'cannot appear in an HTTP header'];
        yield 'bare CR in a header value' => [['headers' => ['X-A' => "v\rX"]], 'cannot appear in an HTTP header'];
        yield 'NUL in a header value' => [['headers' => ['X-A' => "v\0x"]], 'cannot appear in an HTTP header'];
        yield 'CRLF in a header name' => [['headers' => ["X-A\r\nX-Injected" => 'v']], 'cannot appear in an HTTP header'];
        yield 'NUL in a header name' => [['headers' => ["X-A\0" => 'v']], 'cannot appear in an HTTP header'];
        yield 'colon in a header name' => [['headers' => ['X-A: 1' => 'v']], 'cannot appear in an HTTP header'];
        yield 'empty header name' => [['headers' => ['' => 'v']], 'cannot appear in an HTTP header'];
        yield 'CRLF in the idempotency key' => [['idempotency_key' => "k\r\nX-Injected: 1"], 'cannot contain a CR, LF or NUL'];
    }

    /**
     * @param array<string, mixed> $opts
     */
    #[DataProvider('badOptions')]
    public function testABadOptionThrowsBeforeAnyRequest(array $opts, string $message): void
    {
        $http = new MockHttpClient();

        try {
            $this->makeClient($http)->customers->create(['email' => 'a@b.co'], $opts);
            self::fail('expected \InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
        self::assertSame([], $http->requests);
    }

    public function testIdempotencyKeyInParamsIsRefusedRatherThanDroppedOrSent(): void
    {
        $http = new MockHttpClient();

        try {
            $this->makeClient($http)->refunds->create(['payment_id' => 'pay_1', 'idempotency_key' => 'refund-1']);
            self::fail('expected \InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('request options', $e->getMessage());
        }
        self::assertSame([], $http->requests);
    }

    public function testAPsrTimeoutIsAnApiTimeoutException(): void
    {
        $http = (new MockHttpClient())
            ->stageError(new MockNetworkException('cURL error 28: Operation timed out after 300 milliseconds'));

        try {
            $this->makeClient($http, $this->fastRetry(1))->customers->retrieve('cus_1');
            self::fail('expected a timeout');
        } catch (ApiTimeoutException $e) {
            self::assertInstanceOf(MockNetworkException::class, $e->getPrevious());
        }
    }

    public function testAnOtherConnectionFailureIsNotATimeout(): void
    {
        $http = (new MockHttpClient())->stageError(new MockNetworkException('cURL error 6: Could not resolve host'));

        try {
            $this->makeClient($http, $this->fastRetry(1))->customers->retrieve('cus_1');
            self::fail('expected a connection failure');
        } catch (ApiConnectionException $e) {
            self::assertNotInstanceOf(ApiTimeoutException::class, $e);
        }
    }

    public function testAQueryValueWithNoQueryStringFormIsRefused(): void
    {
        $http = new MockHttpClient();

        $this->expectException(\InvalidArgumentException::class);
        $this->makeClient($http)->customers->all(['email' => new \stdClass()]);
    }
}
