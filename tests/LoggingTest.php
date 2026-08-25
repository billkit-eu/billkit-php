<?php

declare(strict_types=1);

namespace BillKit\Tests;

use BillKit\Exception\ServerException;
use BillKit\Tests\Support\MockHttpClient;
use BillKit\Tests\Support\RecordingLogger;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;

/**
 * The SDK's logging must be opt-in and leak-free.
 *
 * Two properties are load-bearing and easy to regress:
 *
 * 1. **Silent by default.** A library that picks a log destination takes
 *    over its host application's logging. Without an injected PSR-3
 *    logger the SDK must write nowhere at all.
 * 2. **No secrets, ever.** API keys, request/response bodies and query
 *    strings must never reach a log record. The caller's log sink is
 *    not somewhere a payments SDK gets to put customer PII.
 */
final class LoggingTest extends BillKitTestCase
{
    public function testDefaultsToNullLoggerAndEmitsNothing(): void
    {
        $http = (new MockHttpClient())
            ->stage(503, ['error' => ['type' => 'api_error', 'message' => 'down']])
            ->stage(200, ['id' => 'cus_1']);
        // No logger argument at all: the production default path. The
        // 503 first makes the retry path run too, since that's where a
        // stray warning would most plausibly be emitted.
        $client = $this->makeClient($http);

        // Nothing to assert on a NullLogger beyond "it didn't blow up and
        // didn't print". The output-buffer check catches an echo/var_dump
        // sneaking in, which is the PHP-shaped version of this mistake.
        ob_start();
        $client->customers->create(['email' => 'a@b.co']);
        $printed = (string) ob_get_clean();
        self::assertSame('', $printed, 'The SDK wrote to stdout without being asked.');
    }

    public function testNullLoggerIsAcceptedExplicitly(): void
    {
        $http = (new MockHttpClient())->stage(200, ['id' => 'cus_1']);
        $client = $this->makeClient($http, null, new NullLogger());

        $result = $client->customers->create(['email' => 'a@b.co']);
        self::assertSame('cus_1', $result['id']);
    }

    public function testLogsRequestAndResponseAtDebug(): void
    {
        $logger = new RecordingLogger();
        $http = (new MockHttpClient())->stage(200, ['id' => 'cus_1'], ['x-request-id' => 'req_abc']);
        $client = $this->makeClient($http, null, $logger);

        $client->customers->create(['email' => 'a@b.co']);

        $debug = $logger->withLevel(LogLevel::DEBUG);
        self::assertCount(2, $debug);

        self::assertSame('BillKit request', $debug[0]['message']);
        self::assertSame('POST', $debug[0]['context']['method']);
        self::assertSame(self::BASE_URL . '/v1/customers', $debug[0]['context']['url']);
        self::assertSame(1, $debug[0]['context']['attempt']);

        self::assertSame('BillKit response', $debug[1]['message']);
        self::assertSame(200, $debug[1]['context']['status']);
        self::assertSame('req_abc', $debug[1]['context']['request_id']);
        self::assertIsInt($debug[1]['context']['duration_ms']);
    }

    public function testLogsOneWarningPerRetry(): void
    {
        $logger = new RecordingLogger();
        $http = (new MockHttpClient())
            ->stage(503, ['error' => ['type' => 'api_error', 'message' => 'down']])
            ->stage(200, ['id' => 'cus_1']);
        $client = $this->makeClient($http, null, $logger);

        $client->customers->create(['email' => 'a@b.co']);

        $warnings = $logger->withLevel(LogLevel::WARNING);
        self::assertCount(1, $warnings);
        self::assertSame('BillKit retrying', $warnings[0]['message']);
        self::assertSame('HTTP 503', $warnings[0]['context']['reason']);
        self::assertSame(1, $warnings[0]['context']['attempt']);
    }

    public function testRaisesFinalFailureInsteadOfLoggingIt(): void
    {
        $logger = new RecordingLogger();
        $http = (new MockHttpClient())
            ->stage(500, ['error' => ['type' => 'api_error', 'message' => 'boom']])
            ->stage(500, ['error' => ['type' => 'api_error', 'message' => 'boom']])
            ->stage(500, ['error' => ['type' => 'api_error', 'message' => 'boom']]);
        $client = $this->makeClient($http, null, $logger);

        // The thrown exception carries status, requestId and retryAfter.
        // Logging it here too would hand the caller a duplicate entry
        // they never asked for and cannot suppress.
        $this->expectException(ServerException::class);
        try {
            $client->customers->create(['email' => 'a@b.co']);
        } finally {
            self::assertSame([], $logger->withLevel(LogLevel::ERROR));
            self::assertSame([], $logger->withLevel(LogLevel::CRITICAL));
        }
    }

    public function testNeverLogsApiKeyBodiesOrQueryString(): void
    {
        $logger = new RecordingLogger();
        $http = (new MockHttpClient())
            ->stage(200, ['id' => 'cus_1', 'email' => 'ada@example.com', 'name' => 'Ada Lovelace'])
            ->stage(200, ['object' => 'list', 'data' => [], 'has_more' => false]);
        $client = $this->makeClient($http, null, $logger);

        $client->customers->create(['email' => 'ada@example.com', 'name' => 'Ada Lovelace']);
        $client->auditLogs->all(['actor_id' => 'act_secret_filter']);

        $blob = $logger->blob();
        self::assertNotSame('', $blob, 'Expected log records; the rest would pass vacuously.');
        self::assertStringNotContainsString('sk_test_unit', $blob, 'The API key reached a log record.');
        self::assertStringNotContainsString('Bearer', $blob, 'The Authorization header reached a log record.');
        self::assertStringNotContainsString('ada@example.com', $blob, 'A body (PII) reached a log record.');
        self::assertStringNotContainsString('Ada Lovelace', $blob, 'A body (PII) reached a log record.');
        self::assertStringNotContainsString('act_secret_filter', $blob, 'A query value reached a log record.');
        self::assertStringNotContainsString('?', $blob, 'A query string was appended to the logged url.');
    }
}
