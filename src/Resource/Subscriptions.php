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
     * @return array<string, mixed>
     */
    public function retrieve(string $id): array
    {
        return $this->get("/v1/subscriptions/{$id}");
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
     * @param array<string, scalar|null> $params
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
     * @param array<string, scalar|null> $filters
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
        return $this->postEmpty("/v1/subscriptions/{$id}/cancel", $idempotencyKey);
    }

    /**
     * Pause an active subscription.
     *
     * @return array<string, mixed>
     */
    public function pause(string $id, ?string $idempotencyKey = null): array
    {
        return $this->postEmpty("/v1/subscriptions/{$id}/pause", $idempotencyKey);
    }

    /**
     * Resume a paused subscription (paused -> active).
     *
     * @return array<string, mixed>
     */
    public function resume(string $id, ?string $idempotencyKey = null): array
    {
        return $this->postEmpty("/v1/subscriptions/{$id}/resume", $idempotencyKey);
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
        return $this->postEmpty("/v1/subscriptions/{$id}/reactivate", $idempotencyKey);
    }

    /**
     * Preview the proration of switching to another price, without applying it.
     *
     * @return array<string, mixed>
     */
    public function previewUpdate(string $id, string $targetPriceId): array
    {
        return $this->postFixed(
            "/v1/subscriptions/{$id}/preview_update",
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
            "/v1/subscriptions/{$id}/update",
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
            "/v1/subscriptions/{$id}/reauthorize_payment_method",
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
     * `occurred_at` (epoch seconds) and `metadata`, plus the reserved
     * `idempotency_key` entry. Retrying with the same key returns the
     * same record instead of double-counting the usage, which is what
     * makes at-least-once reporting pipelines safe.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function createUsageRecord(string $id, array $params): array
    {
        return $this->post("/v1/subscriptions/{$id}/usage_records", $params);
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
        return $this->get("/v1/subscriptions/{$id}/usage_records", $params);
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
            fn (array $p): array => $this->get("/v1/subscriptions/{$id}/usage_records", $p),
            $pageSize,
            ['invoice_id' => $invoiceId],
        );
    }
}
