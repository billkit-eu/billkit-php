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
     * ``['expand' => ['prices']]`` attaches every price on the product
     * (archived ones too, active first); ``'stats'`` attaches the live
     * subscriber and revenue counts; ``'default_price'`` attaches the price
     * ``default_price_id`` names. Those three are the only relations this
     * route expands.
     *
     * @param array<string, scalar|list<string>|null> $params ``expand`` only
     *
     * @return array<string, mixed>
     */
    public function retrieve(string $id, array $params = []): array
    {
        return $this->get('/v1/products/' . self::p($id), $params);
    }

    /**
     * Patch mutable fields on a product, or archive it.
     *
     * `['active' => false]` archives: the product stops being offered and
     * a checkout against any of its prices is refused. It keeps its id
     * and stays readable, because what was sold under it has to be, which
     * is why there is no delete. `['active' => true]` un-archives.
     *
     * ``default_price_id`` names the price the billing portal offers on
     * that price's interval. It must be an active price of this product;
     * anything else throws {@see \BillKit\Exception\InvalidRequestException}
     * on ``default_price_id``. Unlike the other keys here, a ``null``
     * ``default_price_id`` or ``description`` is a value, not an omission:
     * ``['default_price_id' => null]`` is sent as an explicit JSON null and
     * **clears** the default, and ``['description' => null]`` removes the
     * description. Leave the key out to keep the stored value. Other
     * ``null`` values are still stripped.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function update(string $id, array $params): array
    {
        return $this->postClearable(
            '/v1/products/' . self::p($id),
            $params,
            ['default_price_id', 'description'],
        );
    }

    /**
     * List one page of products. Use {@see self::autoPagingIterator()} to
     * walk every page.
     *
     * ``['expand' => ['prices', 'stats', 'default_price']]`` is accepted
     * here too, and is resolved for the whole page in one query rather
     * than per row.
     *
     * @param array<string, scalar|list<string>|null> $params
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
