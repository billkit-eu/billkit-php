<?php

declare(strict_types=1);

namespace BillKit\Resource;

use BillKit\Collection;
use BillKit\Model\AuditLog;

/**
 * The audit log: who did what in the account, and when.
 *
 * @internal constructed by {@see \BillKit\BillKitClient}; use `$client->auditLogs`.
 */
final class AuditLogs extends BaseResource
{
    /**
     * One audit-log entry.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return AuditLog
     */
    public function retrieve(string $id, array $params = [], array $opts = []): AuditLog
    {
        return $this->getObject('/v1/audit_logs/' . self::p($id), $params, $opts, AuditLog::class);
    }

    /**
     * One page of audit-log entries, newest first.
     *
     * Filters: `action`, `resource_type`, `resource_id` (everything that ever
     * happened to one object) and `actor_id`.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return Collection<AuditLog>
     */
    public function all(array $params = [], array $opts = []): Collection
    {
        return $this->getList('/v1/audit_logs', $params, $opts, AuditLog::class);
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
     * @return \Generator<int, AuditLog>
     */
    public function autoPagingIterator(array $params = [], array $opts = []): \Generator
    {
        yield from $this->all($params, $opts)->autoPagingIterator();
    }
}
