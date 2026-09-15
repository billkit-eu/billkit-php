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
     * Archive a price so it stops selling, or put it back on sale.
     *
     * Pass `['active' => false]` to archive. The price keeps its id and
     * is still returned by {@see self::retrieve()} and {@see self::all()},
     * because subscriptions renew against it by id and what they are
     * charged has to stay readable. Subscriptions already on the price
     * go on renewing against it; what stops is new business, so a
     * checkout against it is refused and it is no longer offered as a
     * plan change.
     *
     * Pass `['active' => true]` to undo that. `active` is the only field
     * because the amount, currency and interval are fixed at creation,
     * and since none of them move here neither direction can change what
     * a past charge was made under.
     *
     * Sending the value a price already has returns it unchanged and
     * emits no second event, so a retry is safe. Archiving emits
     * `price.archived`; putting one back emits `price.updated`.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function update(string $id, array $params): array
    {
        return $this->post("/v1/prices/{$id}", $params);
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
