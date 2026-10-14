<?php

declare(strict_types=1);

namespace BillKit\Resource;

use BillKit\Collection;
use BillKit\Model\Invoice;
use BillKit\Model\InvoiceEmail;

/**
 * Invoices: the documents issued for each charge.
 *
 * @internal constructed by {@see \BillKit\BillKitClient}; use `$client->invoices`.
 */
final class Invoices extends BaseResource
{
    /**
     * One invoice, with its line items.
     *
     * `['expand' => ['customer']]` attaches the buyer summary. The invoice's own
     * `*_at_invoice_time` fields are snapshots of who was billed, so they stay
     * right even when the customer has since been edited.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return Invoice
     */
    public function retrieve(string $id, array $params = [], array $opts = []): Invoice
    {
        return $this->getObject('/v1/invoices/' . self::p($id), $params, $opts, Invoice::class);
    }

    /**
     * The rendered invoice PDF, as raw bytes.
     *
     *     file_put_contents('invoice.pdf', $client->invoices->retrievePdf('inv_123'));
     *
     * Blob-backed deployments stream the bytes inline; S3-backed ones answer
     * `302` to a presigned URL, which the transport follows under the SDK's own
     * timeout and retry policy, so both storage adapters look identical from
     * here and the API key never travels to the storage host.
     *
     * A deployment that does not render PDFs answers `501 rendering_pending`, a
     * {@see \BillKit\Exception\ServerException} whose `errorCode` is
     * `rendering_pending`.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return string
     */
    public function retrievePdf(string $id, array $params = [], array $opts = []): string
    {
        return $this->getBytes('/v1/invoices/' . self::p($id) . '/pdf', $opts);
    }

    /**
     * Send the customer their invoice again.
     *
     * The same tenant-branded "your invoice is ready" email, with a fresh portal
     * link, because the one in the original may have expired. It goes to the
     * address captured **on the invoice**, not the customer's current one. An
     * invoice with no address on file is an {@see
     * \BillKit\Exception\InvalidRequestException} rather than a send that
     * quietly did not happen.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return InvoiceEmail
     */
    public function sendEmail(string $id, array $params = [], array $opts = []): InvoiceEmail
    {
        return $this->postAction('/v1/invoices/' . self::p($id) . '/email', $params, $opts, InvoiceEmail::class);
    }

    /**
     * Void an invoice: state that the sale was never owed.
     *
     * The invoice keeps its number and stays readable (a gapless series cannot
     * lose a row) and stops being a receivable. `reason` is optional.
     *
     * A **paid** invoice is refused with a {@see
     * \BillKit\Exception\ConflictException} whose `errorCode` is
     * `invoice_not_voidable`: once money has moved, the document that reverses a
     * sale is a credit note, issued when a refund settles. Voiding an
     * already-void invoice returns it unchanged.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return Invoice
     */
    public function void(string $id, array $params = [], array $opts = []): Invoice
    {
        return $this->postObject('/v1/invoices/' . self::p($id) . '/void', $params, $opts, Invoice::class);
    }

    /**
     * One page of invoices.
     *
     * Filters: `customer_id`, `subscription_id`, `payment_id` ("which invoice
     * did this charge produce") and `status` (one of `open`, `paid`, `void`,
     * `uncollectible`). `['expand' => ['customer']]` is accepted. The list
     * carries no line items; {@see self::retrieve()} has the breakdown.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return Collection<Invoice>
     */
    public function all(array $params = [], array $opts = []): Collection
    {
        return $this->getList('/v1/invoices', $params, $opts, Invoice::class);
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
     * @return \Generator<int, Invoice>
     */
    public function autoPagingIterator(array $params = [], array $opts = []): \Generator
    {
        yield from $this->all($params, $opts)->autoPagingIterator();
    }
}
