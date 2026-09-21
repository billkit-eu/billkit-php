<?php

declare(strict_types=1);

namespace BillKit\Resource;

use BillKit\Collection;

/**
 * Read-only access to credit notes — the documents that reverse an
 * issued invoice.
 *
 * There is no create. A credit note is issued for you when a refund
 * settles, never on request, so a numbered legal record is only minted
 * once the money has actually moved. A refund that is still pending, a
 * refund that fails, and a refund of a one-off charge that was never
 * invoiced all produce none.
 */
final class CreditNotes extends BaseResource
{
    /**
     * Fetch a single credit note by id.
     *
     * @return array<string, mixed>
     */
    public function retrieve(string $id): array
    {
        return $this->get("/v1/credit_notes/{$id}");
    }

    /**
     * Download the rendered credit note PDF as raw bytes.
     *
     *     file_put_contents('credit-note.pdf', $client->creditNotes->retrievePdf('cn_123'));
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
     * structured credit note for tenants who render their own.
     */
    public function retrievePdf(string $id): string
    {
        return $this->transport->requestBytes('GET', "/v1/credit_notes/{$id}/pdf");
    }

    /**
     * List one page of credit notes, newest first.
     *
     * `invoice_id` answers "was this sale credited, and by how much",
     * which is the question when reconciling a single invoice;
     * `customer_id` answers it for everything credited to one buyer.
     *
     * @param array<string, scalar|null> $params
     *
     * @return array<string, mixed>
     */
    public function all(array $params = []): array
    {
        return $this->get('/v1/credit_notes', $params);
    }

    /**
     * Yield every credit note across all pages, optionally narrowed to
     * one invoice or one customer.
     *
     * @return \Generator<int, mixed>
     */
    public function autoPagingIterator(
        ?int $pageSize = null,
        ?string $invoiceId = null,
        ?string $customerId = null,
    ): \Generator {
        yield from Collection::autoPagingIterator(
            fn (array $p): array => $this->get('/v1/credit_notes', $p),
            $pageSize,
            [
                'invoice_id' => $invoiceId,
                'customer_id' => $customerId,
            ],
        );
    }
}
