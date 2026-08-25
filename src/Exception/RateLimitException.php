<?php

declare(strict_types=1);

namespace BillKit\Exception;

/**
 * 429: too many requests. Carries the parsed ``Retry-After`` (seconds)
 * when the server supplied one, so callers that catch this can back off
 * for exactly as long as the API asked.
 */
class RateLimitException extends BillKitException
{
    public function __construct(
        string $message,
        ?string $errorType = null,
        ?string $errorCode = null,
        ?string $param = null,
        ?int $statusCode = null,
        ?string $requestId = null,
        mixed $rawBody = null,
        public readonly ?float $retryAfter = null,
    ) {
        parent::__construct($message, $errorType, $errorCode, $param, $statusCode, $requestId, $rawBody);
    }
}
