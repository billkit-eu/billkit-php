<?php

declare(strict_types=1);

namespace BillKit\Resource;

use BillKit\Collection;
use BillKit\Model\ApiKey;

/**
 * API keys: issue, inspect and revoke secret keys.
 *
 * @internal constructed by {@see \BillKit\BillKitClient}; use `$client->apiKeys`.
 */
final class ApiKeys extends BaseResource
{
    /**
     * Issue a new key.
     *
     * The response's `secret` is the only time the full key exists outside your
     * own storage, so record it now; it is never retrievable again. `scopes`
     * narrows what the key may do, which is the point of minting one per
     * integration rather than sharing a single key; omit it and the new key
     * inherits the calling key's own. An unrecognised scope is rejected at
     * creation rather than failing later on every call.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return ApiKey
     */
    public function create(array $params = [], array $opts = []): ApiKey
    {
        return $this->postObject('/v1/api_keys', $params, $opts, ApiKey::class);
    }

    /**
     * One key's metadata: prefix, label, scopes, `revoked_at`.
     *
     * The key itself is never returned. `last_used_at` is the useful field,
     * since it tells you whether a key is still in service before you revoke it.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return ApiKey
     */
    public function retrieve(string $id, array $params = [], array $opts = []): ApiKey
    {
        return $this->getObject('/v1/api_keys/' . self::p($id), $params, $opts, ApiKey::class);
    }

    /**
     * Revoke a key so it stops working.
     *
     * Immediate and irreversible; issue a new key instead. Revoking an
     * already-revoked key returns it unchanged, so a retry is safe, and a key
     * may revoke itself, which is what you want when the leaked key is the one
     * you are calling with.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return ApiKey
     */
    public function revoke(string $id, array $params = [], array $opts = []): ApiKey
    {
        return $this->postAction('/v1/api_keys/' . self::p($id) . '/revoke', $params, $opts, ApiKey::class);
    }

    /**
     * One page of API keys, newest first.
     *
     * Only keys in the calling key's mode are listed. Revoked ones are included,
     * so check `revoked_at`.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return Collection<ApiKey>
     */
    public function all(array $params = [], array $opts = []): Collection
    {
        return $this->getList('/v1/api_keys', $params, $opts, ApiKey::class);
    }

    /**
     * Every item across all pages.
     *
     * Takes the same `$params` as {@see self::all()}; the filters are carried
     * onto every page request, so a filtered walk narrows server-side. `limit`
     * sets the page size.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return \Generator<int, ApiKey>
     */
    public function autoPagingIterator(array $params = [], array $opts = []): \Generator
    {
        yield from $this->all($params, $opts)->autoPagingIterator();
    }
}
