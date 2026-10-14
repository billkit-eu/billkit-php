<?php

declare(strict_types=1);

namespace BillKit\Exception;

/**
 * 429: too many requests.
 *
 * `retryAfter` is the server's `Retry-After` in seconds, or null when it sent
 * none. The SDK already waited and retried when the value was short (30
 * seconds or less by default), so reaching this means the wait was longer
 * than that or absent.
 */
class RateLimitException extends ApiException
{
    /**
     * @internal built by the SDK from a response
     *
     * @param array<string, mixed>|null $rawBody
     * @param array<string, string>     $headers
     */
    public function __construct(
        string $message,
        int $statusCode,
        ?string $errorType = null,
        ?string $errorCode = null,
        ?string $param = null,
        ?string $reason = null,
        ?string $requestId = null,
        ?array $rawBody = null,
        array $headers = [],
        public readonly ?float $retryAfter = null,
        ?\Throwable $previous = null,
        ?string $httpBody = null,
    ) {
        parent::__construct(
            $message,
            $statusCode,
            $errorType,
            $errorCode,
            $param,
            $reason,
            $requestId,
            $rawBody,
            $headers,
            $previous,
            $httpBody,
        );
    }
}
