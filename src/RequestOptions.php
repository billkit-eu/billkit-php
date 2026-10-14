<?php

declare(strict_types=1);

namespace BillKit;

/**
 * The `$opts` array every resource method takes as its last argument, parsed
 * and checked before any request is made.
 *
 * | Key | Type | Meaning |
 * |---|---|---|
 * | `idempotency_key` | string | Sent as `Idempotency-Key`. Blank counts as unset, and one is generated. |
 * | `timeout` | int or float, seconds | This call's timeout. Bundled curl transport only; an injected PSR-18 client keeps its own. |
 * | `max_retries` | int, 0 or more | Retries after the first attempt; `0` sends it once. |
 * | `headers` | array<string, string> | Extra request headers. |
 *
 * An unknown key throws `\InvalidArgumentException`, so a misspelt
 * `idempotencyKey` cannot silently send a request without one.
 *
 * @internal
 */
final class RequestOptions
{
    private const KNOWN = ['idempotency_key', 'timeout', 'max_retries', 'headers'];

    /** Headers the SDK owns; each has a dedicated option or none at all. */
    private const RESERVED_HEADERS = ['authorization', 'idempotency-key', 'content-type', 'user-agent'];

    /**
     * @param array<string, string> $headers
     */
    private function __construct(
        public readonly ?string $idempotencyKey,
        public readonly ?float $timeout,
        public readonly ?int $maxRetries,
        public readonly array $headers,
    ) {
    }

    /**
     * @param array<array-key, mixed> $opts
     *
     * @throws \InvalidArgumentException
     */
    public static function parse(array $opts): self
    {
        foreach (array_keys($opts) as $key) {
            if (!in_array($key, self::KNOWN, true)) {
                throw new \InvalidArgumentException(sprintf(
                    "BillKit: unknown request option '%s'. Expected one of: %s.",
                    (string) $key,
                    implode(', ', self::KNOWN),
                ));
            }
        }

        $key = $opts['idempotency_key'] ?? null;
        if ($key !== null && !is_string($key)) {
            throw new \InvalidArgumentException('BillKit: the idempotency_key option must be a string.');
        }
        // A blank key counts as "not supplied": sending `Idempotency-Key:`
        // with no value while the retry loop re-sent the POST quietly turned
        // off the one thing that makes a retried charge safe.
        if ($key !== null && trim($key) === '') {
            $key = null;
        }
        if ($key !== null && strpbrk($key, "\r\n\0") !== false) {
            throw new \InvalidArgumentException(
                'BillKit: the idempotency_key option cannot contain a CR, LF or NUL; it is sent as a header.',
            );
        }

        $timeout = $opts['timeout'] ?? null;
        if ($timeout !== null) {
            if (!is_int($timeout) && !is_float($timeout)) {
                throw new \InvalidArgumentException('BillKit: the timeout option is a number of seconds greater than 0.');
            }
            Transport::assertTimeout((float) $timeout, 'the timeout option');
        }

        $maxRetries = $opts['max_retries'] ?? null;
        if ($maxRetries !== null && (!is_int($maxRetries) || $maxRetries < 0)) {
            throw new \InvalidArgumentException('BillKit: the max_retries option is an int, 0 or more.');
        }

        $headers = [];
        $rawHeaders = $opts['headers'] ?? [];
        if (!is_array($rawHeaders)) {
            throw new \InvalidArgumentException('BillKit: the headers option is an array of name => value strings.');
        }
        foreach ($rawHeaders as $name => $value) {
            if (!is_string($name) || !is_string($value)) {
                throw new \InvalidArgumentException('BillKit: the headers option is an array of name => value strings.');
            }
            // The bundled curl transport writes "Name: value" lines verbatim,
            // so a CR or LF would start a second header (or the body) of the
            // caller's choosing, and a NUL truncates the line inside libcurl.
            // A PSR-18 client may refuse these itself, but curl does not.
            if ($name === '' || strpbrk($name, "\r\n\0:") !== false || strpbrk($value, "\r\n\0") !== false) {
                throw new \InvalidArgumentException(sprintf(
                    "BillKit: the header '%s' has an empty name, or a CR, LF, NUL (or ':' in the name), which "
                    . 'cannot appear in an HTTP header.',
                    addcslashes($name, "\0..\37"),
                ));
            }
            if (in_array(strtolower($name), self::RESERVED_HEADERS, true)) {
                throw new \InvalidArgumentException(sprintf(
                    "BillKit: the '%s' header is set by the SDK%s.",
                    $name,
                    strtolower($name) === 'idempotency-key' ? '; pass the idempotency_key option instead' : '',
                ));
            }
            $headers[$name] = $value;
        }

        return new self($key, $timeout === null ? null : (float) $timeout, $maxRetries, $headers);
    }
}
