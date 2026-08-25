<?php

declare(strict_types=1);

namespace BillKit\Resource;

use BillKit\Collection;

/**
 * Read-only access to generated invoices.
 *
 * Invoices are produced by the billing pipeline; tenants don't create
 * them directly. PDF retrieval on the API returns a 302 to the storage
 * adapter's signed URL.
 */
final class Invoices extends BaseResource
{
    /**
     * Fetch a single invoice by id.
     *
     * @return array<string, mixed>
     */
    public function retrieve(string $id): array
    {
        return $this->get("/v1/invoices/{$id}");
    }

    /**
     * List one page of invoices. Use {@see self::autoPagingIterator()} to
     * walk every page.
     *
     * @param array<string, scalar|null> $params
     *
     * @return array<string, mixed>
     */
    public function all(array $params = []): array
    {
        return $this->get('/v1/invoices', $params);
    }

    /**
     * Yield every invoice across all pages.
     *
     * @return \Generator<int, mixed>
     */
    public function autoPagingIterator(?int $pageSize = null): \Generator
    {
        yield from Collection::autoPagingIterator(
            fn (array $p): array => $this->get('/v1/invoices', $p),
            $pageSize,
        );
    }
}
