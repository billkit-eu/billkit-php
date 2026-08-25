<?php

declare(strict_types=1);

namespace BillKit\Resource;

use BillKit\Collection;

/**
 * Read-only access to the per-tenant audit log.
 *
 * Supports server-side filters ``action``, ``resource_type``,
 * ``actor_id``, forwarded through ``autoPagingIterator()`` so a walk can
 * scope to a single actor or action without client-side filtering.
 */
final class AuditLogs extends BaseResource
{
    /**
     * Fetch a single audit-log entry by id.
     *
     * @return array<string, mixed>
     */
    public function retrieve(string $id): array
    {
        return $this->get("/v1/audit_logs/{$id}");
    }

    /**
     * List one page of audit-log entries. Use {@see self::autoPagingIterator()}
     * to walk every page.
     *
     * @param array<string, scalar|null> $params
     *
     * @return array<string, mixed>
     */
    public function all(array $params = []): array
    {
        return $this->get('/v1/audit_logs', $params);
    }

    /**
     * Yield every audit-log entry across all pages, optionally scoped by
     * ``$action`` / ``$resourceType`` / ``$actorId``.
     *
     * @return \Generator<int, mixed>
     */
    public function autoPagingIterator(
        ?int $pageSize = null,
        ?string $action = null,
        ?string $resourceType = null,
        ?string $actorId = null,
    ): \Generator {
        yield from Collection::autoPagingIterator(
            fn (array $p): array => $this->get('/v1/audit_logs', $p),
            $pageSize,
            [
                'action' => $action,
                'resource_type' => $resourceType,
                'actor_id' => $actorId,
            ],
        );
    }
}
