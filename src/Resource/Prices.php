<?php

declare(strict_types=1);

namespace BillKit\Resource;

use BillKit\Collection;

/** Immutable billing terms attached to a Product. */
final class Prices extends BaseResource
{
    /**
     * Create billing terms for an existing product.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function create(array $params): array
    {
        return $this->post('/v1/prices', $params);
    }

    /**
     * Fetch a single price by id.
     *
     * @return array<string, mixed>
     */
    public function retrieve(string $id): array
    {
        return $this->get("/v1/prices/{$id}");
    }

    /**
     * List one page of prices. Use {@see self::autoPagingIterator()} to
     * walk every page.
     *
     * @param array<string, scalar|null> $params
     *
     * @return array<string, mixed>
     */
    public function all(array $params = []): array
    {
        return $this->get('/v1/prices', $params);
    }

    /**
     * Yield every price across all pages.
     *
     * @return \Generator<int, mixed>
     */
    public function autoPagingIterator(?int $pageSize = null): \Generator
    {
        yield from Collection::autoPagingIterator(
            fn (array $p): array => $this->get('/v1/prices', $p),
            $pageSize,
        );
    }
}
