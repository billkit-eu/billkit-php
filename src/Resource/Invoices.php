<?php

declare(strict_types=1);

namespace BillKit\Resource;

use BillKit\Collection;

/**
 * Read-only access to generated invoices.
 *
 * Invoices are produced by the billing pipeline; tenants don't create
 * them directly. PDF retrieval on the API returns a 302 to the storage
 * adapter's signed URL.
 */
final class Invoices extends BaseResource
{
    /**
     * Fetch a single invoice by id.
     *
     * @return array<string, mixed>
     */
    public function retrieve(string $id): array
    {
        return $this->get("/v1/invoices/{$id}");
    }

    /**
     * List one page of invoices. Use {@see self::autoPagingIterator()} to
     * walk every page.
     *
     * @param array<string, scalar|null> $params
     *
     * @return array<string, mixed>
     */
    public function all(array $params = []): array
    {
        return $this->get('/v1/invoices', $params);
    }

    /**
     * Yield every invoice across all pages.
     *
     * @return \Generator<int, mixed>
     */
    public function autoPagingIterator(?int $pageSize = null): \Generator
    {
        yield from Collection::autoPagingIterator(
            fn (array $p): array => $this->get('/v1/invoices', $p),
            $pageSize,
        );
    }

    /**
     * Void an invoice: state that the sale was never owed.
     *
     * The invoice keeps its number and stays readable — a gapless series
     * cannot lose a row — and stops being a receivable. Use it for an
     * invoice that should not have been issued.
     *
     * A **paid** invoice is refused with a {@see \BillKit\Exception\ConflictException}
     * whose ``code`` is ``invoice_not_voidable``. That is deliberate: once the
     * money has moved, "never owed" is false, and the document that reverses a
     * real sale is a credit note — refund the payment and one is issued when
     * the refund settles.
     *
     * Idempotent: voiding an already-void invoice returns it unchanged.
     *
     * @param array<string, mixed> $params ``reason`` (audit row only) and
     *                                     ``idempotency_key``
     *
     * @return array<string, mixed>
     */
    public function void(string $id, array $params = []): array
    {
        return $this->post("/v1/invoices/{$id}/void", $params);
    }
}
