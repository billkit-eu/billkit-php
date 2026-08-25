<?php

declare(strict_types=1);

namespace BillKit\Resource;

use BillKit\Collection;

/** Catalog products; attach one or more Prices to each. */
final class Products extends BaseResource
{
    /**
     * Create a catalog product.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function create(array $params): array
    {
        return $this->post('/v1/products', $params);
    }

    /**
     * Fetch a single product by id.
     *
     * @return array<string, mixed>
     */
    public function retrieve(string $id): array
    {
        return $this->get("/v1/products/{$id}");
    }

    /**
     * Patch mutable fields on a product.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function update(string $id, array $params): array
    {
        return $this->post("/v1/products/{$id}", $params);
    }

    /**
     * Archive a product.
     *
     * @return array<string, mixed>
     */
    public function delete(string $id, ?string $idempotencyKey = null): array
    {
        return $this->del("/v1/products/{$id}", $idempotencyKey);
    }

    /**
     * List one page of products. Use {@see self::autoPagingIterator()} to
     * walk every page.
     *
     * @param array<string, scalar|null> $params
     *
     * @return array<string, mixed>
     */
    public function all(array $params = []): array
    {
        return $this->get('/v1/products', $params);
    }

    /**
     * Yield every product across all pages.
     *
     * @return \Generator<int, mixed>
     */
    public function autoPagingIterator(?int $pageSize = null): \Generator
    {
        yield from Collection::autoPagingIterator(
            fn (array $p): array => $this->get('/v1/products', $p),
            $pageSize,
        );
    }
}
