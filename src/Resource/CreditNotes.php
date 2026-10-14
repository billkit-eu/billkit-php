<?php

declare(strict_types=1);

namespace BillKit\Resource;

use BillKit\Collection;
use BillKit\Model\CreditNote;

/**
 * Credit notes: the documents that reverse part or all of an invoice.
 *
 * @internal constructed by {@see \BillKit\BillKitClient}; use `$client->creditNotes`.
 */
final class CreditNotes extends BaseResource
{
    /**
     * One credit note.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return CreditNote
     */
    public function retrieve(string $id, array $params = [], array $opts = []): CreditNote
    {
        return $this->getObject('/v1/credit_notes/' . self::p($id), $params, $opts, CreditNote::class);
    }

    /**
     * The rendered credit note PDF, as raw bytes.
     *
     *     file_put_contents('credit-note.pdf', $client->creditNotes->retrievePdf('cn_123'));
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
        return $this->getBytes('/v1/credit_notes/' . self::p($id) . '/pdf', $opts);
    }

    /**
     * One page of credit notes, newest first.
     *
     * `invoice_id` answers "was this sale credited, and by how much";
     * `customer_id` answers it for everything credited to one buyer.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return Collection<CreditNote>
     */
    public function all(array $params = [], array $opts = []): Collection
    {
        return $this->getList('/v1/credit_notes', $params, $opts, CreditNote::class);
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
     * @return \Generator<int, CreditNote>
     */
    public function autoPagingIterator(array $params = [], array $opts = []): \Generator
    {
        yield from $this->all($params, $opts)->autoPagingIterator();
    }
}
