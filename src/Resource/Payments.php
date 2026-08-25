<?php

declare(strict_types=1);

namespace BillKit\Resource;

use BillKit\Collection;

/**
 * Read-only access to the payment ledger.
 *
 * Payments are written by the billing pipeline (checkout, renewal,
 * reauthorize). Refunds and disputes are separate flows.
 */
final class Payments extends BaseResource
{
    /**
     * Fetch a single payment by id.
     *
     * @return array<string, mixed>
     */
    public function retrieve(string $id): array
    {
        return $this->get("/v1/payments/{$id}");
    }

    /**
     * List one page of payments. Use {@see self::autoPagingIterator()} to
     * walk every page.
     *
     * @param array<string, scalar|null> $params
     *
     * @return array<string, mixed>
     */
    public function all(array $params = []): array
    {
        return $this->get('/v1/payments', $params);
    }

    /**
     * Yield every payment across all pages.
     *
     * @return \Generator<int, mixed>
     */
    public function autoPagingIterator(?int $pageSize = null): \Generator
    {
        yield from Collection::autoPagingIterator(
            fn (array $p): array => $this->get('/v1/payments', $p),
            $pageSize,
        );
    }
}
