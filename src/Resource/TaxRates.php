<?php

declare(strict_types=1);

namespace BillKit\Resource;

use BillKit\Collection;

/** Per-country tax rates applied to invoices. */
final class TaxRates extends BaseResource
{
    /**
     * Create a tax rate for a country.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function create(array $params): array
    {
        return $this->post('/v1/tax_rates', $params);
    }

    /**
     * Fetch a single tax rate by id.
     *
     * @return array<string, mixed>
     */
    public function retrieve(string $id): array
    {
        return $this->get("/v1/tax_rates/{$id}");
    }

    /**
     * Patch mutable fields on a tax rate.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function update(string $id, array $params): array
    {
        return $this->post("/v1/tax_rates/{$id}", $params);
    }

    /**
     * Archive a tax rate.
     *
     * @return array<string, mixed>
     */
    public function delete(string $id, ?string $idempotencyKey = null): array
    {
        return $this->del("/v1/tax_rates/{$id}", $idempotencyKey);
    }

    /**
     * List one page of tax rates. Use {@see self::autoPagingIterator()} to
     * walk every page.
     *
     * @param array<string, scalar|null> $params
     *
     * @return array<string, mixed>
     */
    public function all(array $params = []): array
    {
        return $this->get('/v1/tax_rates', $params);
    }

    /**
     * Yield every tax rate across all pages.
     *
     * @return \Generator<int, mixed>
     */
    public function autoPagingIterator(?int $pageSize = null): \Generator
    {
        yield from Collection::autoPagingIterator(
            fn (array $p): array => $this->get('/v1/tax_rates', $p),
            $pageSize,
        );
    }
}
