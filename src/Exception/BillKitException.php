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
 * The HTTP **status** picks the subclass, so callers ``catch`` the subclass
 * they care about rather than branching on status codes themselves. The
 * envelope's ``type``/``code``/``param`` ride along on the thrown object.
 * See {@see self::classForStatus()} for why the status, not ``type``, is the
 * authority.
 *
 * ``type`` and ``code`` are exposed as ``errorType`` / ``errorCode``
 * because PHP's ``\Exception`` already reserves ``$code`` (an int) and
 * ``getCode()``.
 */
class BillKitException extends \RuntimeException
{
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

        $class = self::classForStatus($status);
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

    /**
     * The ``type`` the API would have sent, for a response that carried no
     * envelope. Only fills ``errorType``; it never picks the class.
     */
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
     * Pick the exception subclass from the HTTP **status**, not the envelope
     * ``type``.
     *
     * The status is the field the API cannot get wrong. ``type`` is accurate
     * for errors BillKit raises itself, but a request that never reaches a
     * route handler — an unmatched path, a method the route does not allow —
     * is serialised by the framework-level handler as
     * ``{"type": "api_error", "code": "unhandled"}`` *with a 4xx status*.
     * Trusting ``type`` there mapped a plain ``404 Not Found`` (a typo in a
     * resource id, or an SDK/API version skew) onto {@see ServerException},
     * telling the caller BillKit had broken when their own request was at
     * fault — and ``ServerException`` is the class retry and alerting
     * policies key on.
     *
     * ``errorType`` still carries the envelope value verbatim; only the class
     * is status-driven. The node and python clients decide this the same way.
     *
     * @return class-string<BillKitException>
     */
    private static function classForStatus(int $status): string
    {
        return match (true) {
            $status >= 500 => ServerException::class,
            $status === 401 => AuthenticationException::class,
            $status === 403 => PermissionException::class,
            $status === 404 => ResourceMissingException::class,
            $status === 409 => ConflictException::class,
            $status === 429 => RateLimitException::class,
            // Everything else below 500 (400, 405, 422, 451 ...) is a request
            // the caller has to change.
            default => InvalidRequestException::class,
        };
    }
}
