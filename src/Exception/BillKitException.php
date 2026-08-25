<?php

declare(strict_types=1);

namespace BillKit\Exception;

/**
 * Base exception mirroring the BillKit API error envelope.
 *
 * The API returns errors in the Stripe shape:
 *
 *     { "error": { "type": "...", "code": "...", "message": "...", "param": "..." } }
 *
 * Each ``type`` maps to one subclass so callers ``catch`` the subclass
 * they care about rather than branching on HTTP status codes.
 *
 * ``type`` and ``code`` are exposed as ``errorType`` / ``errorCode``
 * because PHP's ``\Exception`` already reserves ``$code`` (an int) and
 * ``getCode()``.
 */
class BillKitException extends \RuntimeException
{
    /**
     * @var array<string, class-string<BillKitException>>
     */
    private const TYPE_TO_CLASS = [
        'api_connection_error' => ApiConnectionException::class,
        // ``api_error`` is the Stripe-convention type for 5xx, so surface it
        // as ServerException (a subclass of ApiException).
        'api_error' => ServerException::class,
        'authentication_error' => AuthenticationException::class,
        'permission_error' => PermissionException::class,
        'invalid_request_error' => InvalidRequestException::class,
        'idempotency_error' => ConflictException::class,
        'conflict' => ConflictException::class,
        'rate_limit_error' => RateLimitException::class,
    ];

    public function __construct(
        string $message,
        public readonly ?string $errorType = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $param = null,
        public readonly ?int $statusCode = null,
        public readonly ?string $requestId = null,
        public readonly mixed $rawBody = null,
    ) {
        parent::__construct($message);
    }

    /**
     * Build the right exception subclass from a non-2xx response.
     *
     * @param array<string, mixed>|null $body
     */
    public static function fromResponse(
        int $status,
        ?array $body,
        ?string $requestId = null,
        ?float $retryAfter = null,
    ): self {
        $envelope = [];
        if (is_array($body) && isset($body['error']) && is_array($body['error'])) {
            $envelope = $body['error'];
        }

        $type = self::stringOrNull($envelope['type'] ?? null) ?? self::fallbackType($status);
        $code = self::stringOrNull($envelope['code'] ?? null);
        $param = self::stringOrNull($envelope['param'] ?? null);
        $message = self::stringOrNull($envelope['message'] ?? null)
            ?? "BillKit API returned HTTP {$status} with no error body.";

        $class = self::TYPE_TO_CLASS[$type] ?? self::fallbackClass($status);
        if ($status === 404 && $class === InvalidRequestException::class) {
            $class = ResourceMissingException::class;
        }
        if ($status === 409 && $class === InvalidRequestException::class) {
            $class = ConflictException::class;
        }

        $rawBody = is_array($body) ? $body : null;

        if ($class === RateLimitException::class) {
            return new RateLimitException(
                $message,
                $type,
                $code,
                $param,
                $status,
                $requestId,
                $rawBody,
                $retryAfter,
            );
        }

        return new $class($message, $type, $code, $param, $status, $requestId, $rawBody);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function fallbackType(int $status): string
    {
        return match (true) {
            $status === 401 => 'authentication_error',
            $status === 403 => 'permission_error',
            $status === 404 => 'invalid_request_error',
            $status === 409 => 'conflict',
            $status === 429 => 'rate_limit_error',
            $status >= 500 => 'api_error',
            default => 'invalid_request_error',
        };
    }

    /**
     * @return class-string<BillKitException>
     */
    private static function fallbackClass(int $status): string
    {
        return match (true) {
            $status === 401 => AuthenticationException::class,
            $status === 403 => PermissionException::class,
            $status === 404 => ResourceMissingException::class,
            $status === 409 => ConflictException::class,
            $status === 429 => RateLimitException::class,
            $status >= 500 => ServerException::class,
            default => InvalidRequestException::class,
        };
    }
}
