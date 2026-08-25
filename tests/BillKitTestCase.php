<?php

declare(strict_types=1);

namespace BillKit\Tests;

use BillKit\BillKitClient;
use BillKit\RetryPolicy;
use BillKit\Tests\Support\MockHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Log\LoggerInterface;

abstract class BillKitTestCase extends TestCase
{
    protected const BASE_URL = 'https://test.billkit.eu';

    /** A zero-backoff policy so retry tests never actually sleep. */
    protected function fastRetry(int $maxAttempts = 3): RetryPolicy
    {
        return new RetryPolicy(
            maxAttempts: $maxAttempts,
            initialBackoffMs: 0,
            backoffMultiplier: 1.0,
            maxBackoffMs: 0,
            maxRetryAfterMs: null,
            jitter: 0.0,
        );
    }

    protected function makeClient(
        MockHttpClient $http,
        ?RetryPolicy $retry = null,
        ?LoggerInterface $logger = null,
    ): BillKitClient {
        $psr17 = new Psr17Factory();

        return new BillKitClient(
            apiKey: 'sk_test_unit',
            baseUrl: self::BASE_URL,
            retryPolicy: $retry ?? $this->fastRetry(),
            httpClient: $http,
            requestFactory: $psr17,
            streamFactory: $psr17,
            logger: $logger,
        );
    }

    protected function url(RequestInterface $request): string
    {
        return (string) $request->getUri();
    }

    /** @return array<string, mixed> */
    protected function bodyArray(RequestInterface $request): array
    {
        $raw = (string) $request->getBody();
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
