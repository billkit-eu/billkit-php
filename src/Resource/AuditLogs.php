<?php

declare(strict_types=1);

namespace BillKit\Resource;

use BillKit\Collection;

/**
 * Read-only access to the per-tenant audit log.
 *
 * Supports server-side filters ``action``, ``resource_type``,
 * ``resource_id`` and ``actor_id``, forwarded through
 * ``autoPagingIterator()`` so a walk can scope to a single actor, action
 * or row without client-side filtering.
 *
 * All four match exactly and combine. ``resource_type`` narrows to a kind
 * (``customer``, ``price``); ``resource_id`` narrows to one row, which is
 * the "everything that ever happened to this customer" question an audit
 * log mostly exists for. Pair them or pass ``resource_id`` alone — ids are
 * already unique.
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
        return $this->get('/v1/audit_logs/' . self::p($id));
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
     * ``$action`` / ``$resourceType`` / ``$actorId`` / ``$resourceId``.
     *
     * ``$resourceId`` is last rather than beside ``$resourceType``, where it
     * belongs by meaning, because these are positional parameters: inserting
     * it in the middle would silently re-bind the fourth argument of every
     * existing four-argument call from an actor id to a resource id, and both
     * are opaque strings that no type check would catch. Reach for named
     * arguments (``resourceId: $id``) and the order stops mattering.
     *
     * @return \Generator<int, mixed>
     */
    public function autoPagingIterator(
        ?int $pageSize = null,
        ?string $action = null,
        ?string $resourceType = null,
        ?string $actorId = null,
        ?string $resourceId = null,
    ): \Generator {
        yield from Collection::autoPagingIterator(
            fn (array $p): array => $this->get('/v1/audit_logs', $p),
            $pageSize,
            [
                'action' => $action,
                'resource_type' => $resourceType,
                'actor_id' => $actorId,
                'resource_id' => $resourceId,
            ],
        );
    }
}
