<?php

declare(strict_types=1);

namespace BillKit\Resource;

use BillKit\Collection;
use BillKit\Model\TaxRate;

/**
 * Tax rates: the VAT percentage applied per country.
 *
 * @internal constructed by {@see \BillKit\BillKitClient}; use `$client->taxRates`.
 */
final class TaxRates extends BaseResource
{
    /**
     * Create a tax rate for a country.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return TaxRate
     */
    public function create(array $params = [], array $opts = []): TaxRate
    {
        return $this->postObject('/v1/tax_rates', $params, $opts, TaxRate::class);
    }

    /**
     * One tax rate.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return TaxRate
     */
    public function retrieve(string $id, array $params = [], array $opts = []): TaxRate
    {
        return $this->getObject('/v1/tax_rates/' . self::p($id), $params, $opts, TaxRate::class);
    }

    /**
     * Change a tax rate.
     *
     * `['display_name' => null]` is sent as an explicit JSON null and removes
     * the display name; leave the key out to keep it. Other `null` values are
     * dropped. `['active' => false]` retires the rate; it stays readable,
     * because issued invoices record what it produced.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return TaxRate
     */
    public function update(string $id, array $params = [], array $opts = []): TaxRate
    {
        return $this->postObject('/v1/tax_rates/' . self::p($id), $params, $opts, TaxRate::class, ['display_name']);
    }

    /**
     * One page of tax rates.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return Collection<TaxRate>
     */
    public function all(array $params = [], array $opts = []): Collection
    {
        return $this->getList('/v1/tax_rates', $params, $opts, TaxRate::class);
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
     * @return \Generator<int, TaxRate>
     */
    public function autoPagingIterator(array $params = [], array $opts = []): \Generator
    {
        yield from $this->all($params, $opts)->autoPagingIterator();
    }
}
