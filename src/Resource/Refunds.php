<?php

declare(strict_types=1);

namespace BillKit\Resource;

use BillKit\Collection;

/** Issue and inspect refunds against payments, subscriptions or one-shot payments. */
final class Refunds extends BaseResource
{
    /**
     * Issue a refund against a payment, subscription or one-shot payment.
     *
     * Pass exactly one of ``payment_id``, ``subscription_id`` or
     * ``one_shot_payment_id`` to select the target; they are mutually
     * exclusive. Add an optional ``amount_cents`` for a partial refund; omit
     * it to refund the whole remaining balance. A payment may carry several
     * partial refunds up to the charged amount.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function create(array $params): array
    {
        return $this->post('/v1/refunds', $params);
    }

    /**
     * Fetch a single refund by id.
     *
     * @return array<string, mixed>
     */
    public function retrieve(string $id): array
    {
        return $this->get("/v1/refunds/{$id}");
    }

    /**
     * List one page of refunds. Use {@see self::autoPagingIterator()} to
     * walk every page.
     *
     * @param array<string, scalar|null> $params
     *
     * @return array<string, mixed>
     */
    public function all(array $params = []): array
    {
        return $this->get('/v1/refunds', $params);
    }

    /**
     * Yield every refund across all pages.
     *
     * @return \Generator<int, mixed>
     */
    public function autoPagingIterator(?int $pageSize = null): \Generator
    {
        yield from Collection::autoPagingIterator(
            fn (array $p): array => $this->get('/v1/refunds', $p),
            $pageSize,
        );
    }
}
