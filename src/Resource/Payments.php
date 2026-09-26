<?php

declare(strict_types=1);

namespace BillKit\Resource;

use BillKit\Collection;

/**
 * Read-only access to the payment ledger.
 *
 * Payments are written by the billing pipeline (checkout, renewal,
 * reauthorize). Refunds and disputes are separate flows.
 */
final class Payments extends BaseResource
{
    /**
     * Fetch a single payment by id.
     *
     * ``['expand' => ['customer', 'subscription']]`` attaches the buyer and
     * the subscription this charge belongs to.
     *
     * ``['expand' => ['refund_eligibility']]`` attaches whether a refund of
     * the remaining balance would succeed now, applying the refund window
     * and the price's refund policy, which ``amount_refundable_cents`` does
     * not: ``['object' => 'refund_eligibility', 'eligible', 'amount_cents',
     * 'currency', 'days_remaining', 'window_ends_at', 'reason']``. When not
     * eligible, ``reason`` is ``not_paid``, ``unrefundable_type``,
     * ``window_expired``, ``fully_refunded``, ``disputed`` (an open
     * chargeback took the balance), ``operation_pending`` (another refund
     * is still being confirmed) or ``plan_change_pending`` (a plan change is
     * settling: the full balance cannot be refunded yet, a partial refund
     * still can). Treat any other reason as "not refundable". This relation is retrieve-only: {@see self::all()}
     * refuses it with an {@see \BillKit\Exception\InvalidRequestException}.
     *
     * @param array<string, scalar|list<string>|null> $params ``expand`` only
     *
     * @return array<string, mixed>
     */
    public function retrieve(string $id, array $params = []): array
    {
        return $this->get('/v1/payments/' . self::p($id), $params);
    }

    /**
     * Fetch the provider's own record of this charge, live.
     *
     * Reads Mollie at request time rather than a stored copy, so it carries
     * what BillKit deliberately does not keep: the card BIN, the iDEAL
     * bank, the provider's own status string. Reading live means it can
     * fail, and a provider outage or a charge old enough to have aged out
     * answers ``200`` with ``available: false`` and a short reason rather
     * than an error, so render the rest of the page regardless.
     *
     * @return array<string, mixed>
     */
    public function retrieveProvider(string $id): array
    {
        return $this->get('/v1/payments/' . self::p($id) . '/provider');
    }

    /**
     * List one page of payments. Use {@see self::autoPagingIterator()} to
     * walk every page.
     *
     * ``customer_id`` narrows to one buyer's charges. Failed and pending
     * attempts are listed alongside settled ones, so read ``status`` before
     * treating a row as revenue; mandate verifications are never listed, so
     * every row is a real purchase attempt. One-off charges are not here.
     *
     * ``['expand' => ['customer', 'subscription']]`` is accepted too, and is
     * resolved for the whole page in one query rather than per row.
     *
     * @param array<string, scalar|list<string>|null> $params
     *
     * @return array<string, mixed>
     */
    public function all(array $params = []): array
    {
        return $this->get('/v1/payments', $params);
    }

    /**
     * Yield every payment across all pages, optionally narrowed to one
     * customer. The filter is carried onto every page request, so a
     * filtered walk narrows server-side instead of paging the whole
     * ledger to find the tail of the match.
     *
     * @return \Generator<int, mixed>
     */
    public function autoPagingIterator(?int $pageSize = null, ?string $customerId = null): \Generator
    {
        yield from Collection::autoPagingIterator(
            fn (array $p): array => $this->get('/v1/payments', $p),
            $pageSize,
            ['customer_id' => $customerId],
        );
    }
}
