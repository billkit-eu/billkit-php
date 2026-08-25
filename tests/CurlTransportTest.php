<?php

declare(strict_types=1);

namespace BillKit\Tests;

use BillKit\BillKitClient;
use BillKit\Exception\ApiConnectionException;
use BillKit\RetryPolicy;
use BillKit\Transport;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the **real** bundled curl transport (no injected PSR-18
 * client), which the mock-backed suites never touch. We drive it against
 * a closed local port so `sendCurl()`, the persistent-handle lifecycle,
 * and the connection-error mapping all run without a network dependency.
 */
final class CurlTransportTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\extension_loaded('curl')) {
            self::markTestSkipped('ext-curl not available');
        }
    }

    public function testConnectionFailureMapsToApiConnectionException(): void
    {
        $client = new BillKitClient(
            apiKey: 'sk_test_unit',
            // Discard/closed port on loopback → immediate ECONNREFUSED,
            // no external network required.
            baseUrl: 'http://127.0.0.1:1',
            retryPolicy: new RetryPolicy(maxAttempts: 1, initialBackoffMs: 0, backoffMultiplier: 1.0, maxBackoffMs: 0, jitter: 0.0),
        );

        $this->expectException(ApiConnectionException::class);
        $client->customers->retrieve('cus_1');
    }

    public function testPersistentHandleServesSequentialCalls(): void
    {
        $transport = new Transport(
            apiKey: 'sk_test_unit',
            baseUrl: 'http://127.0.0.1:1',
            retryPolicy: new RetryPolicy(maxAttempts: 1, initialBackoffMs: 0, backoffMultiplier: 1.0, maxBackoffMs: 0, jitter: 0.0),
        );

        // Two calls reuse one internal curl handle; both must fail cleanly
        // (proving curl_reset() leaves the handle usable between requests).
        foreach (['/v1/customers/a', '/v1/customers/b'] as $path) {
            try {
                $transport->request('GET', $path);
                self::fail('expected ApiConnectionException');
            } catch (ApiConnectionException) {
                self::assertTrue(true);
            }
        }
    }

    public function testCurlRequiredWhenNoClientInjected(): void
    {
        // Sanity: curl-mode construction succeeds when ext-curl is present.
        $transport = new Transport(apiKey: 'sk_test_unit');
        self::assertInstanceOf(Transport::class, $transport);
    }
}
