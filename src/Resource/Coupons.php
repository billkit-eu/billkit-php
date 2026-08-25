<?php

declare(strict_types=1);

namespace BillKit\Resource;

use BillKit\Collection;

/** Discount coupons and server-side redemption previews. */
final class Coupons extends BaseResource
{
    /**
     * Create a coupon.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function create(array $params): array
    {
        return $this->post('/v1/coupons', $params);
    }

    /**
     * Fetch a single coupon by id.
     *
     * @return array<string, mixed>
     */
    public function retrieve(string $id): array
    {
        return $this->get("/v1/coupons/{$id}");
    }

    /**
     * Patch mutable fields on a coupon.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function update(string $id, array $params): array
    {
        return $this->post("/v1/coupons/{$id}", $params);
    }

    /**
     * Delete a coupon.
     *
     * @return array<string, mixed>
     */
    public function delete(string $id, ?string $idempotencyKey = null): array
    {
        return $this->del("/v1/coupons/{$id}", $idempotencyKey);
    }

    /**
     * Server-side dry-run of a redemption: returns the discount math
     * without atomically claiming the coupon.
     *
     * @param array<string, mixed> $params Requires ``code``, ``price_id``, ``amount_cents``.
     *
     * @return array<string, mixed>
     */
    public function validate(array $params): array
    {
        return $this->postFixed('/v1/coupons/validate', [
            'code' => $params['code'] ?? null,
            'price_id' => $params['price_id'] ?? null,
            'amount_cents' => $params['amount_cents'] ?? null,
        ]);
    }

    /**
     * List one page of coupons. Use {@see self::autoPagingIterator()} to
     * walk every page.
     *
     * @param array<string, scalar|null> $params
     *
     * @return array<string, mixed>
     */
    public function all(array $params = []): array
    {
        return $this->get('/v1/coupons', $params);
    }

    /**
     * Yield every coupon across all pages.
     *
     * @return \Generator<int, mixed>
     */
    public function autoPagingIterator(?int $pageSize = null): \Generator
    {
        yield from Collection::autoPagingIterator(
            fn (array $p): array => $this->get('/v1/coupons', $p),
            $pageSize,
        );
    }
}
