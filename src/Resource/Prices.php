<?php

declare(strict_types=1);

namespace BillKit\Resource;

use BillKit\Collection;
use BillKit\DecimalRate;
use BillKit\Model\Price;

/**
 * Prices: the billing terms of a product.
 *
 * @internal constructed by {@see \BillKit\BillKitClient}; use `$client->prices`.
 */
final class Prices extends BaseResource
{
    /**
     * Create billing terms for an existing product.
     *
     * A licensed price carries `amount_cents`: whole minor units, charged every
     * period. A metered price (`'usage_type' => 'metered'`) prices a unit in
     * exactly one of three ways: `amount_cents` (whole minor units per unit);
     * `unit_amount_decimal`, a rate finer than one minor unit **as a string**
     * (`'0.02'` is 0.02 cents, EUR 0.0002 per unit); or `'billing_scheme' =>
     * 'tiered'` with `tiers` and a required `tiers_mode` (`'graduated'` or
     * `'volume'`), whose last band is `'up_to' => 'inf'`.
     *
     * A float `unit_amount_decimal`, at the price or inside a band, throws
     * `\InvalidArgumentException` before the request, because a PHP float cannot
     * hold 0.0002 exactly; an `int` is accepted and sent as a string. See {@see
     * DecimalRate}.
     *
     * `refund_on_cancel` (`'none'`, `'full'`, `'prorated'`) decides what a
     * cancellation refunds unasked. A metered price must leave it at `'none'`.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return Price
     *
     * @throws \InvalidArgumentException when a decimal rate is a float
     */
    public function create(array $params = [], array $opts = []): Price
    {
        return $this->postObject('/v1/prices', DecimalRate::normalizePriceParams($params), $opts, Price::class);
    }

    /**
     * One price.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return Price
     */
    public function retrieve(string $id, array $params = [], array $opts = []): Price
    {
        return $this->getObject('/v1/prices/' . self::p($id), $params, $opts, Price::class);
    }

    /**
     * Change what a price does next. Omitted fields are left alone.
     *
     * `amount_cents`, `currency`, `interval` and `usage_type` decide what a past
     * charge was, so they are fixed at creation; create a new price to charge
     * something different. This route takes `active` (`false` archives: the
     * price stays readable, subscriptions on it keep renewing, new business
     * stops), `metadata` (replaced whole), `tax_behavior` (settable once while
     * `'unspecified'`), `payment_methods`, `refund_on_cancel` and the two refund
     * windows.
     *
     * `refund_window_initial_days` and `refund_window_renewal_days` take `null`
     * as a value: it is sent as an explicit JSON null and drops the price's
     * override. Every other `null` is dropped.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return Price
     */
    public function update(string $id, array $params = [], array $opts = []): Price
    {
        return $this->postObject(
            '/v1/prices/' . self::p($id),
            $params,
            $opts,
            Price::class,
            ['refund_window_initial_days', 'refund_window_renewal_days'],
        );
    }

    /**
     * One page of prices. `product_id` narrows to one product.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return Collection<Price>
     */
    public function all(array $params = [], array $opts = []): Collection
    {
        return $this->getList('/v1/prices', $params, $opts, Price::class);
    }

    /**
     * Every item across all pages.
     *
     * Takes the same `$params` as {@see self::all()}; the filters are carried
     * onto every page request, so a filtered walk narrows server-side. `limit`
     * sets the page size.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return \Generator<int, Price>
     */
    public function autoPagingIterator(array $params = [], array $opts = []): \Generator
    {
        yield from $this->all($params, $opts)->autoPagingIterator();
    }
}
