<?php

declare(strict_types=1);

namespace BillKit\Resource;

use BillKit\Collection;

/**
 * Inspect chargebacks / disputes. Read-only.
 *
 * Disputes are provider-originated (opened by the cardholder's bank) and
 * surfaced via the ``dispute.created`` / ``dispute.closed`` webhook events.
 * There is no create/update. A dispute's ``status`` is ``open`` or ``won``
 * (chargeback reversed); Mollie exposes no "lost" signal, so an upheld
 * chargeback stays ``open`` (treat any non-``won`` dispute as unresolved).
 */
final class Disputes extends BaseResource
{
    /**
     * Fetch a single dispute by id.
     *
     * @return array<string, mixed>
     */
    public function retrieve(string $id): array
    {
        return $this->get("/v1/disputes/{$id}");
    }

    /**
     * List one page of disputes. Use {@see self::autoPagingIterator()} to
     * walk every page.
     *
     * @param array<string, scalar|null> $params
     *
     * @return array<string, mixed>
     */
    public function all(array $params = []): array
    {
        return $this->get('/v1/disputes', $params);
    }

    /**
     * Yield every dispute across all pages.
     *
     * @return \Generator<int, mixed>
     */
    public function autoPagingIterator(?int $pageSize = null): \Generator
    {
        yield from Collection::autoPagingIterator(
            fn (array $p): array => $this->get('/v1/disputes', $p),
            $pageSize,
        );
    }
}
