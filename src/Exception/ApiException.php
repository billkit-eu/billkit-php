<?php

declare(strict_types=1);

namespace BillKit\Exception;

/**
 * The API answered with an HTTP error. Every status has a subclass, and the
 * **status** picks it, not the envelope's `type`:
 *
 * | Status | Class |
 * |---|---|
 * | 400, other 4xx | {@see InvalidRequestException} |
 * | 401 | {@see AuthenticationException} |
 * | 402 | {@see PaymentException} |
 * | 403 | {@see PermissionDeniedException} |
 * | 404 | {@see NotFoundException} |
 * | 409 | {@see ConflictException} |
 * | 429 | {@see RateLimitException} |
 * | 5xx | {@see ServerException} |
 *
 * The status is the one field nothing in between can get wrong. An
 * unmatched route is a 404 with `type` `invalid_request_error`
 * (`route_not_found`), which is a {@see NotFoundException} here. Before 1.0
 * the same miss read `{"type": "api_error"}` with a 4xx status, and trusting
 * `type` mapped a plain 404 onto the server-error class that retry and
 * alerting policies key on; a proxy in front of the API can still answer
 * that way. `errorType` still carries the envelope value verbatim.
 *
 * The envelope `{"error": {type, code, message, param, reason}}` is spread
 * over the properties below. `type` and `code` are `errorType` and
 * `errorCode`, because `\Exception` already owns `$code` and `getCode()`.
 * `reason` is the finer-grained cause some codes carry: a
 * `409 operation_pending` from cancelling, pausing or changing the plan of a
 * subscription whose renewal is still settling has `'renewal_processing'`.
 *
 * `rawBody` is the decoded body, null when it was not JSON; `httpBody` is the
 * body exactly as received. The base class itself (no subclass) is thrown
 * for a 2xx whose body is not JSON, which is something answering in the
 * API's place: a captive portal, a proxy, a `baseUrl` that is not the API.
 *
 * Quote `requestId` to BillKit support; it identifies the exact call.
 *
 * To fake an API error in your own tests, use {@see self::factory()}.
 */
class ApiException extends BillKitException
{
    /**
     * @internal built by the SDK from a response; see {@see self::fromResponse()}
     *
     * @param array<string, mixed>|null $rawBody the decoded error body, null when it was not JSON
     * @param array<string, string>     $headers response headers, names lowercased
     * @param string|null               $httpBody the response body as received
     */
    public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly ?string $errorType = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $param = null,
        public readonly ?string $reason = null,
        public readonly ?string $requestId = null,
        public readonly ?array $rawBody = null,
        public readonly array $headers = [],
        ?\Throwable $previous = null,
        public readonly ?string $httpBody = null,
    ) {
        parent::__construct($message, $previous);
    }

    /**
     * Build the exception the SDK would throw for an HTTP error, for your
     * own tests:
     *
     *     $e = ApiException::factory(404, ['type' => 'invalid_request_error', 'code' => 'resource_missing']);
     *     $e instanceof NotFoundException; // true
     *
     *     ApiException::factory(429, ['message' => 'Slow down'], ['Retry-After' => '2']);   // RateLimitException, retryAfter 2.0
     *
     * The status picks the subclass, exactly as for a real response.
     * `$error` is the envelope's `error` object (`type`, `code`, `message`,
     * `param`, `reason`); `$headers` are response headers, matched without
     * regard to case (`X-Request-Id` fills `requestId`, `Retry-After` in
     * seconds fills `retryAfter`). The constructors stay internal; this is
     * the supported way.
     *
     * @param array<string, mixed>  $error   the envelope's `error` object
     * @param array<string, string> $headers response headers
     *
     * @throws \InvalidArgumentException for a status that is not an HTTP error (400 to 599)
     */
    public static function factory(int $status, array $error = [], array $headers = []): self
    {
        if ($status < 400 || $status > 599) {
            throw new \InvalidArgumentException(
                "BillKit: ApiException::factory() builds an HTTP error, so the status is 400 to 599, not {$status}.",
            );
        }
        $lowered = [];
        foreach ($headers as $name => $value) {
            $lowered[strtolower($name)] = $value;
        }
        $retryAfter = $lowered['retry-after'] ?? null;
        $body = ['error' => $error];

        return self::fromResponse(
            $status,
            $body,
            $lowered,
            $retryAfter !== null && is_numeric($retryAfter) ? (float) $retryAfter : null,
            json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * Build the right subclass from a non-2xx response.
     *
     * @internal
     *
     * @param array<string, mixed>|null $body    the decoded response body
     * @param array<string, string>     $headers response headers, names lowercased
     */
    public static function fromResponse(
        int $status,
        ?array $body,
        array $headers = [],
        ?float $retryAfter = null,
        ?string $httpBody = null,
    ): self {
        $envelope = [];
        if (is_array($body) && isset($body['error']) && is_array($body['error'])) {
            $envelope = $body['error'];
        }

        $type = self::stringOrNull($envelope['type'] ?? null) ?? self::fallbackType($status);
        $code = self::stringOrNull($envelope['code'] ?? null);
        $param = self::stringOrNull($envelope['param'] ?? null);
        $reason = self::stringOrNull($envelope['reason'] ?? null);
        $message = self::stringOrNull($envelope['message'] ?? null)
            ?? "BillKit API returned HTTP {$status} with no error body.";
        $requestId = $headers['x-request-id'] ?? $headers['request-id'] ?? null;

        if ($status === 429) {
            return new RateLimitException(
                $message,
                $status,
                $type,
                $code,
                $param,
                $reason,
                $requestId,
                $body,
                $headers,
                $retryAfter,
                null,
                $httpBody,
            );
        }

        $class = self::classForStatus($status);

        return new $class($message, $status, $type, $code, $param, $reason, $requestId, $body, $headers, null, $httpBody);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The `type` the API would have sent, for a response that carried no
     * envelope. Only fills `errorType`; it never picks the class.
     */
    private static function fallbackType(int $status): string
    {
        return match (true) {
            $status === 401 => 'authentication_error',
            $status === 402 => 'payment_error',
            $status === 403 => 'permission_error',
            $status === 404 => 'not_found_error',
            $status === 409 => 'conflict_error',
            $status === 429 => 'rate_limit_error',
            $status >= 500 => 'api_error',
            default => 'invalid_request_error',
        };
    }

    /**
     * The subclass for a status. The node and python clients decide this the
     * same way, from the status alone.
     *
     * @return class-string<ApiException>
     */
    private static function classForStatus(int $status): string
    {
        return match (true) {
            $status >= 500 => ServerException::class,
            $status === 401 => AuthenticationException::class,
            $status === 402 => PaymentException::class,
            $status === 403 => PermissionDeniedException::class,
            $status === 404 => NotFoundException::class,
            $status === 409 => ConflictException::class,
            $status === 429 => RateLimitException::class,
            // Everything else below 500 (400, 405, 451 ...) is a request
            // the caller has to change.
            default => InvalidRequestException::class,
        };
    }
}
