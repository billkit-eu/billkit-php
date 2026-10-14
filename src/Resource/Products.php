<?php

declare(strict_types=1);

namespace BillKit\Resource;

use BillKit\Collection;
use BillKit\Model\Product;

/**
 * Products: what you sell. Prices hang off them.
 *
 * @internal constructed by {@see \BillKit\BillKitClient}; use `$client->products`.
 */
final class Products extends BaseResource
{
    /**
     * Create a catalogue product.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return Product
     */
    public function create(array $params = [], array $opts = []): Product
    {
        return $this->postObject('/v1/products', $params, $opts, Product::class);
    }

    /**
     * One product.
     *
     * `['expand' => ['prices']]` attaches every price on the product (archived
     * ones too, active first); `'stats'` attaches live subscriber and revenue
     * counts; `'default_price'` attaches the price `default_price_id` names.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return Product
     */
    public function retrieve(string $id, array $params = [], array $opts = []): Product
    {
        return $this->getObject('/v1/products/' . self::p($id), $params, $opts, Product::class);
    }

    /**
     * Change a product, or archive it.
     *
     * `['active' => false]` archives: the product stops being offered and a
     * checkout against any of its prices is refused, but it keeps its id and
     * stays readable, which is why there is no delete. `['active' => true]` puts
     * it back.
     *
     * `default_price_id`, `description` and `marketing_features` take `null` as
     * a value: it is sent as an explicit JSON null and clears the field. Leave a
     * key out to keep the stored value; every other `null` is dropped.
     * `metadata` replaces the stored object whole.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return Product
     */
    public function update(string $id, array $params = [], array $opts = []): Product
    {
        return $this->postObject(
            '/v1/products/' . self::p($id),
            $params,
            $opts,
            Product::class,
            ['default_price_id', 'description', 'marketing_features'],
        );
    }

    /**
     * One page of products. `['expand' => ['prices', 'stats', 'default_price']]`
     * is accepted, resolved for the whole page in one query.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return Collection<Product>
     */
    public function all(array $params = [], array $opts = []): Collection
    {
        return $this->getList('/v1/products', $params, $opts, Product::class);
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
     * @return \Generator<int, Product>
     */
    public function autoPagingIterator(array $params = [], array $opts = []): \Generator
    {
        yield from $this->all($params, $opts)->autoPagingIterator();
    }
}
