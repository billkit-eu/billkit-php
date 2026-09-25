<?php

declare(strict_types=1);

namespace BillKit\Resource;

use BillKit\Collection;

/**
 * Read-only access to generated invoices.
 *
 * Invoices are produced by the billing pipeline; tenants don't create
 * them directly. {@see self::retrievePdf()} hands back the rendered bytes
 * whichever storage adapter a deployment runs, because the transport
 * resolves the redirect an S3-backed one answers with.
 */
final class Invoices extends BaseResource
{
    /**
     * Fetch a single invoice by id, with its line items.
     *
     * ``['expand' => ['customer']]`` attaches the buyer summary, and is the
     * only relation this route expands. The invoice's own
     * ``*_at_invoice_time`` fields are snapshots of who was billed, so they
     * stay right even when the customer has since been edited.
     *
     * @param array<string, scalar|list<string>|null> $params ``expand`` only
     *
     * @return array<string, mixed>
     */
    public function retrieve(string $id, array $params = []): array
    {
        return $this->get('/v1/invoices/' . self::p($id), $params);
    }

    /**
     * Download the rendered invoice PDF as raw bytes.
     *
     *     file_put_contents('invoice.pdf', $client->invoices->retrievePdf('inv_123'));
     *
     * Blob-backed deployments stream the bytes inline; S3-backed ones answer
     * ``302`` to a presigned URL, which the transport follows under the SDK's
     * own timeout and retry policy — so both storage adapters look identical
     * from here, and the API key never travels to the storage host.
     *
     * Deployments with ``INVOICE_PDF_ENABLED=false`` never render one and
     * answer ``501 rendering_pending``, which surfaces as a
     * {@see \BillKit\Exception\ServerException} whose ``errorCode`` is
     * ``rendering_pending``; {@see self::retrieve()} still returns the
     * structured invoice for tenants who render their own.
     */
    public function retrievePdf(string $id): string
    {
        return $this->transport->requestBytes('GET', '/v1/invoices/' . self::p($id) . '/pdf');
    }

    /**
     * Send the customer their invoice again.
     *
     * The same tenant-branded "your invoice is ready" email, with a fresh
     * portal link, because the one in the original may have expired. It
     * goes to the address captured **on the invoice**, not the customer's
     * current one: this is a copy of a document that was issued to
     * somebody. An invoice with no address on file is an
     * {@see \BillKit\Exception\InvalidRequestException} rather than a send
     * that quietly did not happen.
     *
     * @return array<string, mixed>
     */
    public function sendEmail(string $id, ?string $idempotencyKey = null): array
    {
        return $this->postEmpty('/v1/invoices/' . self::p($id) . '/email', $idempotencyKey);
    }

    /**
     * List one page of invoices. Use {@see self::autoPagingIterator()} to
     * walk every page.
     *
     * Four filters, each narrowing to one row's worth of invoices:
     * ``customer_id``, ``subscription_id``, ``payment_id`` (which answers
     * "which invoice did this charge produce"), and ``status``, one of
     * ``draft`` / ``open`` / ``paid`` / ``void`` / ``uncollectible``.
     * ``['expand' => ['customer']]`` is accepted here too. The list carries
     * no line items; {@see self::retrieve()} is where the breakdown is.
     *
     * @param array<string, scalar|list<string>|null> $params
     *
     * @return array<string, mixed>
     */
    public function all(array $params = []): array
    {
        return $this->get('/v1/invoices', $params);
    }

    /**
     * Yield every invoice across all pages, optionally narrowed.
     *
     * ``$filters`` takes the same id and ``status`` keys {@see self::all()}
     * does and is carried onto every page request, so a filtered walk
     * narrows server-side.
     *
     * @param array<string, scalar|list<string>|null> $filters
     *
     * @return \Generator<int, mixed>
     */
    public function autoPagingIterator(?int $pageSize = null, array $filters = []): \Generator
    {
        yield from Collection::autoPagingIterator(
            fn (array $p): array => $this->get('/v1/invoices', $p),
            $pageSize,
            $filters,
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
        return $this->post('/v1/invoices/' . self::p($id) . '/void', $params);
    }
}
