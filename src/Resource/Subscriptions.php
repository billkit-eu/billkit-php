<?php

declare(strict_types=1);

namespace BillKit\Resource;

use BillKit\Collection;

/** Subscription lifecycle: read, list, and state transitions. */
final class Subscriptions extends BaseResource
{
    /**
     * Fetch a single subscription by id.
     *
     * ``['expand' => ['customer', 'price', 'refund_eligibility']]``
     * attaches the buyer summary, the price (with its product name), and
     * what a cancellation would refund right now. Those three are the only
     * relations this route expands.
     *
     * @param array<string, scalar|list<string>|null> $params ``expand`` only
     *
     * @return array<string, mixed>
     */
    public function retrieve(string $id, array $params = []): array
    {
        return $this->get('/v1/subscriptions/' . self::p($id), $params);
    }

    /**
     * List one page of subscriptions. Use {@see self::autoPagingIterator()}
     * to walk every page.
     *
     * `$params` takes `customer_id`, plus `status` and `renewal_state`,
     * which each accept a comma-separated list (`'active,past_due'`). An
     * unrecognised value is a 400 naming the ones that work rather than
     * being silently ignored.
     *
     * The two filters answer different questions, and confusing them is
     * the usual mistake against this route. `status` is where the
     * subscription stands with its payments: `incomplete`, `trialing`,
     * `active`, `past_due`, `canceled`. `renewal_state` is what happens
     * at the end of the current period: `auto_renew`, `paused`,
     * `canceling`, `stopped`. A paused subscription still reads as
     * `active`, because the customer has paid for the period they are
     * in, so `['renewal_state' => 'paused']` is how you find paused
     * ones. `['status' => 'paused']` is not accepted and throws
     * {@see \BillKit\Exception\InvalidRequestException}.
     *
     * ``['expand' => ['customer', 'price', 'refund_eligibility']]`` is
     * accepted here too, resolved for the whole page in one query.
     *
     * @param array<string, scalar|list<string>|null> $params
     *
     * @return array<string, mixed>
     */
    public function all(array $params = []): array
    {
        return $this->get('/v1/subscriptions', $params);
    }

    /**
     * Yield every subscription across all pages.
     *
     * `$filters` takes the same keys {@see self::all()} does and is
     * carried onto every page request, so a filtered walk narrows
     * server-side. Filtering the pages yourself after the fact means
     * paging the whole history to find the tail of the match.
     *
     * @param array<string, scalar|list<string>|null> $filters
     *
     * @return \Generator<int, mixed>
     */
    public function autoPagingIterator(?int $pageSize = null, array $filters = []): \Generator
    {
        yield from Collection::autoPagingIterator(
            fn (array $p): array => $this->get('/v1/subscriptions', $p),
            $pageSize,
            $filters,
        );
    }

    /**
     * Cancel at period end.
     *
     * @return array<string, mixed>
     */
    public function cancel(string $id, ?string $idempotencyKey = null): array
    {
        return $this->postEmpty('/v1/subscriptions/' . self::p($id) . '/cancel', $idempotencyKey);
    }

    /**
     * Pause an active subscription.
     *
     * @return array<string, mixed>
     */
    public function pause(string $id, ?string $idempotencyKey = null): array
    {
        return $this->postEmpty('/v1/subscriptions/' . self::p($id) . '/pause', $idempotencyKey);
    }

    /**
     * Resume a paused subscription (paused -> active).
     *
     * @return array<string, mixed>
     */
    public function resume(string $id, ?string $idempotencyKey = null): array
    {
        return $this->postEmpty('/v1/subscriptions/' . self::p($id) . '/resume', $idempotencyKey);
    }

    /**
     * Reactivate a canceled-but-still-in-period subscription (distinct
     * from {@see self::resume()}, which is paused -> active). Returns 409
     * if the period has already elapsed.
     *
     * @return array<string, mixed>
     */
    public function reactivate(string $id, ?string $idempotencyKey = null): array
    {
        return $this->postEmpty('/v1/subscriptions/' . self::p($id) . '/reactivate', $idempotencyKey);
    }

    /**
     * Preview the proration of switching to another price, without applying it.
     *
     * @return array<string, mixed>
     */
    public function previewUpdate(string $id, string $targetPriceId): array
    {
        return $this->postFixed(
            '/v1/subscriptions/' . self::p($id) . '/preview_update',
            ['target_price_id' => $targetPriceId],
        );
    }

    /**
     * Switch the subscription to another price (apply the change).
     *
     * @return array<string, mixed>
     */
    public function update(string $id, string $targetPriceId, ?string $idempotencyKey = null): array
    {
        return $this->postFixed(
            '/v1/subscriptions/' . self::p($id) . '/update',
            ['target_price_id' => $targetPriceId],
            $idempotencyKey,
        );
    }

    /**
     * Start a portal re-auth flow to renew a lapsed payment mandate;
     * returns the URL to redirect the customer to.
     *
     * @return array<string, mixed>
     */
    public function reauthorizePaymentMethod(
        string $id,
        string $returnUrl,
        ?string $idempotencyKey = null,
    ): array {
        return $this->postFixed(
            '/v1/subscriptions/' . self::p($id) . '/reauthorize_payment_method',
            ['return_url' => $returnUrl],
            $idempotencyKey,
        );
    }

    /**
     * Report consumption against a metered subscription.
     *
     * Only valid when the subscription's price is `usage_type: "metered"`;
     * a licensed subscription is rejected with `400 parameter_invalid`.
     * `$params` carries `quantity` (1..1_000_000, required), optional
     * `occurred_at` (epoch seconds), `identifier` and `metadata`, plus the
     * reserved `idempotency_key` entry. Records are immutable once written
     * — they are the audit trail behind an invoice line — so there is no
     * update or delete.
     *
     * **Two dedupe mechanisms, covering different failures.**
     * `idempotency_key` covers a retry of *this HTTP request*: sending the
     * same key returns the same record instead of double-counting.
     * `identifier` covers a retry of *your own* call — a job runner
     * replaying a task, a queue delivering twice, your code re-invoking
     * after its own timeout — which arrives at the API as a genuinely new
     * request with a new key, so the transport-level key cannot see it. An
     * identifier is unique within the subscription, and a second report of
     * the same one returns the first record unchanged rather than billing
     * twice. If your reporting pipeline is at-least-once, `identifier` is
     * the one that matters.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function createUsageRecord(string $id, array $params): array
    {
        return $this->post('/v1/subscriptions/' . self::p($id) . '/usage_records', $params);
    }

    /**
     * List one page of usage records for one subscription.
     *
     * Pass `invoice_id => 'pending'` to reconcile what has been reported
     * but not yet billed, or a concrete invoice id to see what that
     * invoice charged for. Use
     * {@see self::autoPagingIteratorUsageRecords()} to walk every page.
     *
     * @param array<string, scalar|null> $params
     *
     * @return array<string, mixed>
     */
    public function listUsageRecords(string $id, array $params = []): array
    {
        return $this->get('/v1/subscriptions/' . self::p($id) . '/usage_records', $params);
    }

    /**
     * Price the pending usage, before the period close bills it.
     *
     * {@see self::listUsageRecords()} with `invoice_id => 'pending'` gives
     * the quantity; this gives the money. `net_cents` / `tax_cents` /
     * `gross_cents` are computed through the same rate or tier table and
     * the same VAT resolution the close itself uses, so it is a forecast of
     * the real invoice rather than an estimate.
     *
     * **Read `will_charge` before promising a customer an amount.** A
     * period whose total is under `minimum_charge_cents` (EUR 1.00) is not
     * charged, because the payment provider would refuse it. The usage is
     * not lost: it stays pending and rolls into the next period, which is
     * then billed for both.
     *
     * `open_invoice_id` names an earlier cycle that is invoiced and still
     * unsettled; while one is open, this period cannot be charged.
     *
     * @return array<string, mixed>
     */
    public function retrieveUsageSummary(string $id): array
    {
        return $this->get('/v1/subscriptions/' . self::p($id) . '/usage_summary');
    }

    /**
     * Yield every usage record for one subscription across all pages.
     *
     * `$invoiceId` is the same filter {@see self::listUsageRecords()}
     * takes and is carried onto every page request: `'pending'` walks
     * only what has been reported but not yet billed, a concrete
     * `inv_…` id walks what that invoice charged for. It has to be
     * threaded through here rather than applied by the caller after the
     * fact — filtering a multi-page walk client-side means paging the
     * whole history to find the tail of it.
     *
     * @return \Generator<int, mixed>
     */
    public function autoPagingIteratorUsageRecords(
        string $id,
        ?int $pageSize = null,
        ?string $invoiceId = null,
    ): \Generator {
        yield from Collection::autoPagingIterator(
            fn (array $p): array => $this->get('/v1/subscriptions/' . self::p($id) . '/usage_records', $p),
            $pageSize,
            ['invoice_id' => $invoiceId],
        );
    }
}
