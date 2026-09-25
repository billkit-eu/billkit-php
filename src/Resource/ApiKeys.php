<?php

declare(strict_types=1);

namespace BillKit\Resource;

use BillKit\Collection;

/**
 * Issue, inspect and revoke API keys.
 *
 * A key is issued in the same mode as the key that created it, so a test
 * key can only mint test keys, and it can never grant scopes it does not
 * hold itself. The secret is returned **once**, on {@see self::create()};
 * every later read carries only the prefix.
 */
final class ApiKeys extends BaseResource
{
    /**
     * Issue a new key.
     *
     * The response's ``secret`` is the only time the full key exists
     * outside your own storage, so record it now; it is never retrievable
     * again. ``scopes`` narrows what the key may do, which is the point of
     * minting one per integration rather than sharing a single key; omit it
     * and the new key inherits the calling key's own. An unrecognised scope
     * is rejected at creation rather than failing later on every call.
     *
     * @param array<string, mixed> $params Optional ``label`` and ``scopes``.
     *
     * @return array<string, mixed>
     */
    public function create(array $params = []): array
    {
        return $this->post('/v1/api_keys', $params);
    }

    /**
     * One key's metadata: prefix, label, scopes, ``revoked_at``.
     *
     * The key itself is never returned. ``last_used_at`` is the useful
     * field, since it tells you whether a key is still in service before
     * you revoke it.
     *
     * @return array<string, mixed>
     */
    public function retrieve(string $id): array
    {
        return $this->get('/v1/api_keys/' . self::p($id));
    }

    /**
     * Revoke a key so it stops working.
     *
     * Immediate and irreversible; issue a new key instead. Revoking an
     * already-revoked key returns it unchanged, so a retry is safe, and a
     * key may revoke itself, which is what you want when the leaked key is
     * the one you are calling with.
     *
     * @return array<string, mixed>
     */
    public function revoke(string $id, ?string $idempotencyKey = null): array
    {
        return $this->postEmpty('/v1/api_keys/' . self::p($id) . '/revoke', $idempotencyKey);
    }

    /**
     * List one page of API keys, newest first.
     *
     * Only keys in the calling key's mode are listed. Revoked ones are
     * included, so check ``revoked_at``.
     *
     * @param array<string, scalar|list<string>|null> $params
     *
     * @return array<string, mixed>
     */
    public function all(array $params = []): array
    {
        return $this->get('/v1/api_keys', $params);
    }

    /**
     * Yield every API key across all pages.
     *
     * @return \Generator<int, mixed>
     */
    public function autoPagingIterator(?int $pageSize = null): \Generator
    {
        yield from Collection::autoPagingIterator(
            fn (array $p): array => $this->get('/v1/api_keys', $p),
            $pageSize,
        );
    }
}
