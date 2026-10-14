<?php

declare(strict_types=1);

namespace BillKit\Tests;

use BillKit\BillKitClient;
use BillKit\Exception\ApiConnectionException;
use BillKit\Exception\ApiTimeoutException;
use BillKit\RetryPolicy;
use PHPUnit\Framework\TestCase;

/**
 * The per-request `timeout` option and the timeout exception, against the
 * real bundled curl transport.
 *
 * The server is a listening socket that is never accepted from: the kernel
 * completes the TCP handshake from the backlog, curl sends its request, and
 * no response ever comes. Only a timeout can end the call.
 */
final class CurlTimeoutTest extends TestCase
{
    /** @var resource|null */
    private $server;

    private string $baseUrl = '';

    protected function setUp(): void
    {
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            self::markTestSkipped("cannot open a loopback socket: {$errstr}");
        }
        $this->server = $server;
        $name = (string) stream_socket_get_name($server, false);
        $this->baseUrl = 'http://' . $name;
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            fclose($this->server);
        }
    }

    private function client(float $timeout = 30.0): BillKitClient
    {
        return new BillKitClient(
            apiKey: 'bk_test_unit',
            baseUrl: $this->baseUrl,
            timeout: $timeout,
            retryPolicy: new RetryPolicy(maxAttempts: 1, initialBackoffMs: 0, maxBackoffMs: 0, jitter: 0.0),
        );
    }

    public function testThePerRequestTimeoutOverridesTheClientsAndRaisesATimeout(): void
    {
        $started = microtime(true);
        try {
            // The client would wait 30 s; the option says 0.3 s.
            $this->client()->customers->retrieve('cus_1', [], ['timeout' => 0.3]);
            self::fail('expected a timeout');
        } catch (ApiTimeoutException $e) {
            // A timeout is still a connection failure, so existing handlers
            // for ApiConnectionException keep catching it.
            self::assertInstanceOf(ApiConnectionException::class, $e);
        }
        $elapsed = microtime(true) - $started;
        self::assertLessThan(5.0, $elapsed, 'the per-request timeout was not applied');
        // Seconds, not milliseconds: 0.3 must wait about 300 ms, not 1.
        self::assertGreaterThan(0.25, $elapsed, 'the timeout was not read as seconds');
    }

    public function testTheClientTimeoutAppliesWhenNoOptionIsGiven(): void
    {
        $this->expectException(ApiTimeoutException::class);
        $this->client(timeout: 0.3)->customers->retrieve('cus_1');
    }

    public function testAWholeNumberOfSecondsIsAccepted(): void
    {
        $started = microtime(true);
        $this->expectException(ApiTimeoutException::class);
        try {
            $this->client()->customers->retrieve('cus_1', [], ['timeout' => 1]);
        } finally {
            $elapsed = microtime(true) - $started;
            self::assertGreaterThan(0.9, $elapsed, 'an int timeout was not read as seconds');
            self::assertLessThan(5.0, $elapsed, 'the per-request timeout was not applied');
        }
    }

    public function testANonPositiveClientTimeoutIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client(timeout: 0.0);
    }
}
