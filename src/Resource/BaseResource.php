<?php

declare(strict_types=1);

namespace BillKit\Resource;

use BillKit\Transport;

/**
 * Shared transport wrapper for every resource family.
 *
 * Resources subclass this so each public method reads as a single line,
 * "verb to path with params", instead of repeating the transport
 * boilerplate. Mirrors the Node SDK's ``BaseResource``.
 *
 * All mutating helpers accept a plain associative ``$params`` array; a
 * reserved ``idempotency_key`` entry (if present) is lifted out of the
 * body and sent as the ``Idempotency-Key`` header.
 */
abstract class BaseResource
{
    public function __construct(protected readonly Transport $transport)
    {
    }

    /**
     * @param array<string, scalar|null> $query
     *
     * @return array<string, mixed>
     */
    protected function get(string $path, array $query = []): array
    {
        return $this->transport->request('GET', $path, $query);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    protected function post(string $path, array $params): array
    {
        [$body, $idempotencyKey] = $this->splitIdempotency($params);

        return $this->transport->request('POST', $path, [], $body, $idempotencyKey);
    }

    /**
     * POST with no body, used by lifecycle verbs (cancel, resume, ...).
     *
     * @return array<string, mixed>
     */
    protected function postEmpty(string $path, ?string $idempotencyKey = null): array
    {
        return $this->transport->request('POST', $path, [], null, $idempotencyKey);
    }

    /**
     * POST with a fully caller-specified body (no undefined-stripping).
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    protected function postFixed(string $path, array $body, ?string $idempotencyKey = null): array
    {
        return $this->transport->request('POST', $path, [], $body, $idempotencyKey);
    }

    /**
     * @return array<string, mixed>
     */
    protected function del(string $path, ?string $idempotencyKey = null): array
    {
        return $this->transport->request('DELETE', $path, [], null, $idempotencyKey);
    }

    /**
     * Split ``idempotency_key`` out of a mutating-call param array and
     * drop ``null`` values from the remaining body (``false``/``0``/``''``
     * are preserved, matching the Node SDK's ``dropUndefined``).
     *
     * @param array<string, mixed> $params
     *
     * @return array{0: array<string, mixed>, 1: ?string}
     */
    protected function splitIdempotency(array $params): array
    {
        $idempotencyKey = null;
        if (isset($params['idempotency_key']) && is_string($params['idempotency_key'])) {
            $idempotencyKey = $params['idempotency_key'];
        }
        unset($params['idempotency_key']);
        $body = array_filter($params, static fn ($v): bool => $v !== null);

        return [$body, $idempotencyKey];
    }

    /**
     * Extract just the idempotency key from a param array (for helpers
     * that build a fixed body themselves, e.g. purge/validate).
     *
     * @param array<string, mixed> $params
     */
    protected function idempotencyKeyOf(array $params): ?string
    {
        return isset($params['idempotency_key']) && is_string($params['idempotency_key'])
            ? $params['idempotency_key']
            : null;
    }
}
