<?php

declare(strict_types=1);

namespace BillKit\Resource;

use BillKit\Collection;
use BillKit\Model\PlanChangePreview;
use BillKit\Model\Reauthorization;
use BillKit\Model\Subscription;
use BillKit\Model\UsageRecord;
use BillKit\Model\UsageSummary;

/**
 * Subscriptions: read, list and change them. They are created by checkout, not here.
 *
 * @internal constructed by {@see \BillKit\BillKitClient}; use `$client->subscriptions`.
 */
final class Subscriptions extends BaseResource
{
    /**
     * One subscription.
     *
     * `['expand' => ['customer', 'price', 'refund_eligibility']]` attaches the
     * buyer summary, the price (with its product name) and what a cancellation
     * would refund right now.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return Subscription
     */
    public function retrieve(string $id, array $params = [], array $opts = []): Subscription
    {
        return $this->getObject('/v1/subscriptions/' . self::p($id), $params, $opts, Subscription::class);
    }

    /**
     * One page of subscriptions.
     *
     * Filters: `customer_id`, `status` and `renewal_state`, the last two each a
     * list (`['active', 'past_due']`), sent comma-joined; a single string is
     * sent as given. An unrecognised value is a 400 naming the ones that work.
     *
     * `status` is where the subscription stands with its payments (`incomplete`,
     * `trialing`, `active`, `past_due`, `canceled`); `renewal_state` is what
     * happens at the end of the period (`auto_renew`, `paused`, `canceling`,
     * `stopped`). A paused subscription is still `active`, because the period is
     * paid for, so `['renewal_state' => ['paused']]` is how you find paused ones.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return Collection<Subscription>
     */
    public function all(array $params = [], array $opts = []): Collection
    {
        return $this->getList('/v1/subscriptions', $params, $opts, Subscription::class);
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
     * @return \Generator<int, Subscription>
     */
    public function autoPagingIterator(array $params = [], array $opts = []): \Generator
    {
        yield from $this->all($params, $opts)->autoPagingIterator();
    }

    /**
     * Cancel at the end of the current period.
     *
     * While a renewal payment is still settling, this, {@see self::pause()} and
     * {@see self::update()} throw a {@see \BillKit\Exception\ConflictException}
     * (`409 operation_pending`, `reason` `'renewal_processing'`) whose message
     * names when to retry.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return Subscription
     */
    public function cancel(string $id, array $params = [], array $opts = []): Subscription
    {
        return $this->postAction('/v1/subscriptions/' . self::p($id) . '/cancel', $params, $opts, Subscription::class);
    }

    /**
     * Pause renewals. `renewal_state` becomes `paused`; `status` stays `active`
     * for the period already paid for.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return Subscription
     */
    public function pause(string $id, array $params = [], array $opts = []): Subscription
    {
        return $this->postAction('/v1/subscriptions/' . self::p($id) . '/pause', $params, $opts, Subscription::class);
    }

    /**
     * Resume a paused subscription's renewals.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return Subscription
     */
    public function resume(string $id, array $params = [], array $opts = []): Subscription
    {
        return $this->postAction('/v1/subscriptions/' . self::p($id) . '/resume', $params, $opts, Subscription::class);
    }

    /**
     * Undo a scheduled cancellation while the period is still running (distinct
     * from {@see self::resume()}, which undoes a pause). A 409 once the period
     * has ended.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return Subscription
     */
    public function reactivate(string $id, array $params = [], array $opts = []): Subscription
    {
        return $this->postAction('/v1/subscriptions/' . self::p($id) . '/reactivate', $params, $opts, Subscription::class);
    }

    /**
     * Price a switch to another price without making it. `target_price_id` is
     * required.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return PlanChangePreview
     */
    public function previewUpdate(string $id, array $params = [], array $opts = []): PlanChangePreview
    {
        return $this->postObject('/v1/subscriptions/' . self::p($id) . '/preview_update', $params, $opts, PlanChangePreview::class);
    }

    /**
     * Switch the subscription to another price. `target_price_id` is required.
     *
     * The returned subscription is usually still on the old price: the switch
     * lands when the proration charge settles, and `subscription.updated`
     * reports it.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return Subscription
     */
    public function update(string $id, array $params = [], array $opts = []): Subscription
    {
        return $this->postObject('/v1/subscriptions/' . self::p($id) . '/update', $params, $opts, Subscription::class);
    }

    /**
     * Start a hosted re-authorisation of the payment mandate; send the customer
     * to the returned `url`. `return_url` is required.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return Reauthorization
     */
    public function reauthorizePaymentMethod(string $id, array $params = [], array $opts = []): Reauthorization
    {
        return $this->postObject('/v1/subscriptions/' . self::p($id) . '/reauthorize_payment_method', $params, $opts, Reauthorization::class);
    }

    /**
     * Report consumption against a metered subscription.
     *
     * `quantity` (1 to 1,000,000) is required; `occurred_at` (Unix seconds),
     * `identifier` and `metadata` are optional. A licensed subscription is
     * refused with a 400. Records are immutable.
     *
     * Two dedupe mechanisms cover different failures. The `idempotency_key`
     * option covers a retry of this HTTP request. `identifier` covers a retry of
     * your own call (a job runner replaying a task, a queue delivering twice),
     * which arrives as a new request with a new key: a second report of the same
     * identifier returns the first record unchanged. If your reporting is
     * at-least-once, `identifier` is the one that matters.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return UsageRecord
     */
    public function createUsageRecord(string $id, array $params = [], array $opts = []): UsageRecord
    {
        return $this->postObject('/v1/subscriptions/' . self::p($id) . '/usage_records', $params, $opts, UsageRecord::class);
    }

    /**
     * One page of usage records for one subscription.
     *
     * `['invoice_id' => 'pending']` lists what has been reported but not yet
     * billed; a concrete invoice id lists what that invoice charged for.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return Collection<UsageRecord>
     */
    public function listUsageRecords(string $id, array $params = [], array $opts = []): Collection
    {
        return $this->getList('/v1/subscriptions/' . self::p($id) . '/usage_records', $params, $opts, UsageRecord::class);
    }

    /**
     * Every usage record for one subscription, across all pages. Takes the same
     * `$params` as {@see self::listUsageRecords()}, carried onto every page.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return \Generator<int, UsageRecord>
     */
    public function autoPagingIteratorUsageRecords(string $id, array $params = [], array $opts = []): \Generator
    {
        yield from $this->listUsageRecords($id, $params, $opts)->autoPagingIterator();
    }

    /**
     * The pending usage, priced the way the period close will price it.
     *
     * **Read `will_charge` before promising a customer an amount.** A period
     * whose total is under `minimum_charge_cents` is not charged, because the
     * provider would refuse it; the usage rolls into the next period.
     * `open_invoice_id` names an earlier cycle still unsettled.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return UsageSummary
     */
    public function retrieveUsageSummary(string $id, array $params = [], array $opts = []): UsageSummary
    {
        return $this->getObject('/v1/subscriptions/' . self::p($id) . '/usage_summary', $params, $opts, UsageSummary::class);
    }
}
