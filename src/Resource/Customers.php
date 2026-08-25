<?php

declare(strict_types=1);

namespace BillKit\Resource;

use BillKit\Collection;

/** Tenant-scoped buyer records. */
final class Customers extends BaseResource
{
    /**
     * Create a customer.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function create(array $params = []): array
    {
        return $this->post('/v1/customers', $params);
    }

    /**
     * Fetch a single customer by id.
     *
     * @return array<string, mixed>
     */
    public function retrieve(string $id): array
    {
        return $this->get("/v1/customers/{$id}");
    }

    /**
     * Patch mutable fields on a customer.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function update(string $id, array $params = []): array
    {
        return $this->post("/v1/customers/{$id}", $params);
    }

    /**
     * Soft-delete a customer (see {@see self::purge()} for hard GDPR erasure).
     *
     * @return array<string, mixed>
     */
    public function delete(string $id, ?string $idempotencyKey = null): array
    {
        return $this->del("/v1/customers/{$id}", $idempotencyKey);
    }

    /**
     * List one page of customers. Use {@see self::autoPagingIterator()} to
     * walk every page.
     *
     * @param array<string, scalar|null> $params
     *
     * @return array<string, mixed>
     */
    public function all(array $params = []): array
    {
        return $this->get('/v1/customers', $params);
    }

    /**
     * Yield every customer across all pages.
     *
     * @return \Generator<int, mixed>
     */
    public function autoPagingIterator(?int $pageSize = null): \Generator
    {
        yield from Collection::autoPagingIterator(
            fn (array $p): array => $this->get('/v1/customers', $p),
            $pageSize,
        );
    }

    /**
     * Attach or replace the customer's VAT number; triggers server-side
     * VIES validation. The response carries ``vat_number_validated``.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function setVatNumber(string $id, array $params): array
    {
        return $this->post("/v1/customers/{$id}/vat_number", $params);
    }

    /**
     * Hard-purge a customer's PII for GDPR erasure (irreversible; distinct
     * from {@see self::delete()} soft-delete). The server requires
     * ``confirmed: true`` as a fat-finger guard; defaulted to ``true``
     * here so callers don't opt in twice.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function purge(string $id, array $params = []): array
    {
        $confirmed = $params['confirmed'] ?? true;

        return $this->postFixed(
            "/v1/customers/{$id}/purge",
            ['confirmed' => $confirmed],
            $this->idempotencyKeyOf($params),
        );
    }
}
