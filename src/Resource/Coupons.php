<?php

declare(strict_types=1);

namespace BillKit\Resource;

use BillKit\Collection;
use BillKit\Model\Coupon;
use BillKit\Model\CouponValidation;

/**
 * Coupons: discounts a checkout or subscription can redeem.
 *
 * @internal constructed by {@see \BillKit\BillKitClient}; use `$client->coupons`.
 */
final class Coupons extends BaseResource
{
    /**
     * Create a coupon.
     *
     * Set exactly one of `percent_off` (an `int` or `float` above 0 and at most
     * 100, with up to two decimal places: `12.5`) and
     * `amount_off_cents` (minor units off the charge). `discount_type`
     * (`'percent'` or `'fixed_amount'`) is optional and implied by whichever you
     * set; when sent it must agree.
     *
     * `currency` (ISO 4217, `'EUR'`) is the currency of `amount_off_cents` and
     * of `min_amount_cents`; the coupon then applies only to prices in it.
     * Required with either when the account has more than one currency enabled,
     * otherwise it defaults to the one currency. A percent coupon with no
     * minimum needs none and applies in any currency.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return Coupon
     */
    public function create(array $params = [], array $opts = []): Coupon
    {
        return $this->postObject('/v1/coupons', $params, $opts, Coupon::class);
    }

    /**
     * One coupon.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return Coupon
     */
    public function retrieve(string $id, array $params = [], array $opts = []): Coupon
    {
        return $this->getObject('/v1/coupons/' . self::p($id), $params, $opts, Coupon::class);
    }

    /**
     * Change a coupon.
     *
     * `['max_redemptions' => null]` removes the redemption cap, `['redeem_by' =>
     * null]` removes the expiry, `['applies_to_price_ids' => null]` lifts the
     * price restriction and `['min_amount_cents' => null]` lifts the minimum:
     * each is sent as an explicit JSON null. Leave a key out to keep the stored
     * value. Other `null` values are dropped, because the API refuses a null on
     * `active` with a 400. `['active' => false]` withdraws the coupon; it stays
     * readable.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return Coupon
     */
    public function update(string $id, array $params = [], array $opts = []): Coupon
    {
        return $this->postObject(
            '/v1/coupons/' . self::p($id),
            $params,
            $opts,
            Coupon::class,
            ['max_redemptions', 'redeem_by', 'applies_to_price_ids', 'min_amount_cents'],
        );
    }

    /**
     * Dry-run a redemption: the discount arithmetic, without claiming the
     * coupon.
     *
     * Only `code` is required. `price_id` also checks the coupon's price
     * restriction, `amount_cents` adds the discount arithmetic and the minimum,
     * and `currency` names the charge's currency when no `price_id` does: a
     * coupon in another currency comes back `valid: false` with `reason:
     * 'coupon_currency_mismatch'`.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return CouponValidation
     */
    public function validate(array $params = [], array $opts = []): CouponValidation
    {
        return $this->postObject('/v1/coupons/validate', $params, $opts, CouponValidation::class);
    }

    /**
     * One page of coupons.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return Collection<Coupon>
     */
    public function all(array $params = [], array $opts = []): Collection
    {
        return $this->getList('/v1/coupons', $params, $opts, Coupon::class);
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
     * @return \Generator<int, Coupon>
     */
    public function autoPagingIterator(array $params = [], array $opts = []): \Generator
    {
        yield from $this->all($params, $opts)->autoPagingIterator();
    }
}
