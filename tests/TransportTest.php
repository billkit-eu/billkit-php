<?php

declare(strict_types=1);

namespace BillKit\Tests;

use BillKit\Exception\ApiConnectionException;
use BillKit\RetryPolicy;
use BillKit\Tests\Support\MockHttpClient;
use BillKit\Tests\Support\MockNetworkException;
use BillKit\Transport;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

/**
 * Direct `Transport` tests for the wire-shaping the resource-level suites
 * don't reach: query serialisation, header assembly, and body decoding.
 */
final class TransportTest extends TestCase
{
    private function transport(MockHttpClient $http): Transport
    {
        $psr17 = new Psr17Factory();

        return new Transport(
            apiKey: 'bk_test_unit',
            baseUrl: 'https://test.billkit.eu',
            retryPolicy: new RetryPolicy(maxAttempts: 1, initialBackoffMs: 0, backoffMultiplier: 1.0, maxBackoffMs: 0, jitter: 0.0),
            httpClient: $http,
            requestFactory: $psr17,
            streamFactory: $psr17,
        );
    }

    public function testQuerySerialisesBoolsAsWordsAndSkipsNull(): void
    {
        $http = (new MockHttpClient())->stage(200, ['ok' => true]);
        $this->transport($http)->request('GET', '/v1/things', [
            'active' => true,
            'archived' => false,
            'omitted' => null,
            'limit' => 25,
        ]);

        $query = (string) $http->lastRequest()->getUri()->getQuery();
        parse_str($query, $parsed);
        self::assertSame('true', $parsed['active']);
        self::assertSame('false', $parsed['archived']);
        self::assertSame('25', $parsed['limit']);
        self::assertArrayNotHasKey('omitted', $parsed);
    }

    public function testBaseUrlTrailingSlashIsTrimmed(): void
    {
        $http = (new MockHttpClient())->stage(200, ['ok' => true]);
        $psr17 = new Psr17Factory();
        $transport = new Transport(
            apiKey: 'bk_test_unit',
            baseUrl: 'https://test.billkit.eu/',
            httpClient: $http,
            requestFactory: $psr17,
            streamFactory: $psr17,
        );

        $transport->request('GET', '/v1/things');

        self::assertSame('https://test.billkit.eu/v1/things', (string) $http->lastRequest()->getUri());
    }

    public function testPathWithoutLeadingSlashIsNormalised(): void
    {
        $http = (new MockHttpClient())->stage(200, ['ok' => true]);
        $this->transport($http)->request('GET', 'v1/things');

        self::assertSame('https://test.billkit.eu/v1/things', (string) $http->lastRequest()->getUri());
    }

    public function testExtraHeadersArePassedThrough(): void
    {
        $http = (new MockHttpClient())->stage(200, ['ok' => true]);
        $this->transport($http)->request('GET', '/v1/things', [], null, null, ['X-Trace-Id' => 'abc123']);

        self::assertSame('abc123', $http->lastRequest()->getHeaderLine('X-Trace-Id'));
    }

    public function testEmptyBodyDecodesToEmptyArray(): void
    {
        $http = (new MockHttpClient())->stage(204, '');
        $result = $this->transport($http)->request('DELETE', '/v1/things/1');

        self::assertSame([], $result);
    }

    public function testExposesRequestIdFromResponseHeader(): void
    {
        $http = (new MockHttpClient())->stage(
            404,
            ['error' => ['type' => 'invalid_request_error', 'message' => 'nope']],
            ['x-request-id' => 'req_abc'],
        );

        try {
            $this->transport($http)->request('GET', '/v1/things/1');
            self::fail('expected an exception');
        } catch (\BillKit\Exception\BillKitException $err) {
            self::assertSame('req_abc', $err->requestId);
            self::assertIsArray($err->rawBody);
        }
    }

    /**
     * An empty body must go out as `{}`, never `[]`.
     *
     * PHP cannot tell an empty map from an empty list, and `json_encode([])`
     * picks the list — which the API rejects with "Input should be a valid
     * dictionary", because no BillKit request body is ever a JSON array.
     * Reached by any route whose body is entirely optional, which is how it
     * was found: `POST /v1/invoices/{id}/void` with no `reason`.
     */
    public function testEmptyBodySerialisesAsAnObjectNotAnArray(): void
    {
        $http = (new MockHttpClient())->stage(200, ['ok' => true]);
        $this->transport($http)->request('POST', '/v1/things', [], []);

        self::assertSame('{}', (string) $http->lastRequest()->getBody());
    }

    /** ...and a body that genuinely carries a list keeps it a list. */
    public function testNestedListsAreNotRewrittenAsObjects(): void
    {
        $http = (new MockHttpClient())->stage(200, ['ok' => true]);
        $this->transport($http)->request('POST', '/v1/things', [], [
            'enabled_events' => ['invoice.paid', 'invoice.voided'],
        ]);

        self::assertSame(
            '{"enabled_events":["invoice.paid","invoice.voided"]}',
            (string) $http->lastRequest()->getBody(),
        );
    }

    public function testGetSendsNoContentTypeHeader(): void
    {
        $http = (new MockHttpClient())->stage(200, ['ok' => true]);
        $this->transport($http)->request('GET', '/v1/things');

        self::assertSame('', $http->lastRequest()->getHeaderLine('Content-Type'));
    }

    public function testConnectionExceptionMessageIsStrippedOfQueryStrings(): void
    {
        // Guzzle and friends interpolate the FULL request URI into their
        // connection-failure messages, which would re-introduce the query
        // string this SDK is careful never to log — straight into the
        // caller's exception handler and error tracker.
        $http = (new MockHttpClient())->stageError(new MockNetworkException(
            'cURL error 6: Could not resolve host for '
            . 'https://test.billkit.eu/v1/customers?email=ada@example.com&limit=25 '
            . '(see https://curl.se/libcurl/c/libcurl-errors.html)',
        ));

        try {
            $this->transport($http)->request('GET', '/v1/customers', ['email' => 'ada@example.com']);
            self::fail('expected an ApiConnectionException');
        } catch (ApiConnectionException $err) {
            self::assertStringNotContainsString('ada@example.com', $err->getMessage());
            self::assertStringNotContainsString('?', $err->getMessage());
            // Still diagnosable: scheme, host and path survive.
            self::assertStringContainsString('https://test.billkit.eu/v1/customers', $err->getMessage());
            self::assertStringContainsString('Could not resolve host', $err->getMessage());
        }
    }

    public function testConnectionExceptionMessageWithoutAUrlIsUntouched(): void
    {
        $http = (new MockHttpClient())->stageError(new MockNetworkException('Connection timed out after 30000ms'));

        try {
            $this->transport($http)->request('GET', '/v1/things');
            self::fail('expected an ApiConnectionException');
        } catch (ApiConnectionException $err) {
            self::assertSame('Connection timed out after 30000ms', $err->getMessage());
        }
    }

    public function testConnectionExceptionKeepsTheClientsOwnExceptionAsPrevious(): void
    {
        // The message the caller sees is sanitised and generic ("cURL
        // error 6"), so the original is the only thing left carrying the
        // rest of the diagnosis. Dropping it threw that away.
        $cause = new MockNetworkException('cURL error 6: Could not resolve host');
        $http = (new MockHttpClient())->stageError($cause);

        try {
            $this->transport($http)->request('GET', '/v1/things');
            self::fail('expected an ApiConnectionException');
        } catch (ApiConnectionException $err) {
            self::assertSame($cause, $err->getPrevious());
        }
    }

    public function testAnApiErrorHasNoPrevious(): void
    {
        $http = (new MockHttpClient())->stage(404, ['error' => ['type' => 'invalid_request_error']]);

        try {
            $this->transport($http)->request('GET', '/v1/things/missing');
            self::fail('expected a BillKitException');
        } catch (\BillKit\Exception\BillKitException $err) {
            self::assertNull($err->getPrevious());
            // ``\Exception::$code`` stays 0; the HTTP status has its own field.
            self::assertSame(0, $err->getCode());
            self::assertSame(404, $err->statusCode);
        }
    }

    public function testExpandIsJoinedWithCommasInTheQuery(): void
    {
        $http = (new MockHttpClient())->stage(200, ['object' => 'list', 'data' => [], 'has_more' => false]);
        $this->transport($http)->request('GET', '/v1/payments', [
            'expand' => ['customer', 'subscription'],
            'limit' => 5,
            // An empty list is nothing to ask for, so it is dropped rather
            // than sent as `expand=`, which the API rejects.
            'starting_after' => null,
        ]);

        self::assertSame(
            'expand=customer%2Csubscription&limit=5',
            $http->lastRequest()->getUri()->getQuery(),
        );
    }
}
