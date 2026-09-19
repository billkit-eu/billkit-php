<?php

declare(strict_types=1);

namespace BillKit\Resource;

use BillKit\Collection;
use BillKit\DecimalRate;

/** Immutable billing terms attached to a Product. */
final class Prices extends BaseResource
{
    /**
     * Create billing terms for an existing product.
     *
     * A licensed price carries `amount_cents`: whole minor units, charged
     * every period. A metered price (`usage_type => 'metered'`) prices a
     * unit in one of three ways, and uses exactly one of them:
     *
     * - `amount_cents` — whole minor units per unit.
     * - `unit_amount_decimal` — a rate finer than one minor unit, in minor
     *   units, to 12 decimal places, **as a string**. `'0.02'` is 0.02
     *   cents, i.e. EUR 0.0002 per unit: the canonical per-API-call price,
     *   which no integer can express. A `float` here throws
     *   `\InvalidArgumentException` before the request is sent, because a
     *   PHP float cannot hold 0.0002 exactly; see {@see DecimalRate}.
     * - `billing_scheme => 'tiered'` with `tiers` and `tiers_mode` — price
     *   by bands. `'graduated'` prices the units inside each band;
     *   `'volume'` lets the period total pick one band which then prices
     *   every unit. The same table under the two modes is a different
     *   bill, so the mode is required rather than defaulted. A band is
     *   `['up_to' => int|'inf', 'unit_amount' => int,
     *   'unit_amount_decimal' => string, 'flat_amount' => int]`; the last
     *   band must be `'inf'`, because a bounded top band cannot price the
     *   usage above it. Write a free band as `'unit_amount' => 0`.
     *
     * A price with none of the three is refused server-side. The period's
     * whole quantity is multiplied by the rate and rounded **once**, at the
     * invoice.
     *
     * `refund_on_cancel` (`'none'` | `'full'` | `'prorated'`) decides what
     * a cancellation refunds without being asked. Both non-none modes also
     * end access immediately, which is why a metered price must leave it at
     * `'none'`: ending access mid-period would strand usage that has not
     * been billed yet.
     *
     * @param array<string, mixed> $params
     *
     * @throws \InvalidArgumentException when a decimal rate is a float
     *
     * @return array<string, mixed>
     */
    public function create(array $params): array
    {
        return $this->post('/v1/prices', DecimalRate::normalizePriceParams($params));
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
