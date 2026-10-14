<?php

declare(strict_types=1);

namespace BillKit\Resource;

use BillKit\Collection;
use BillKit\Model\OneShotPayment;

/**
 * One-off, mandate-less payments.
 *
 * @internal constructed by {@see \BillKit\BillKitClient}; use `$client->oneShotPayments`.
 */
final class OneShotPayments extends BaseResource
{
    /**
     * Create a one-shot payment: a single charge with no stored mandate, so it
     * can never be replayed later.
     *
     * Hosted (the default) needs a `payment_method`, and the response has a
     * `redirect_url` to send the buyer to. Embedded (`'ui_mode' => 'embedded'`)
     * is sent WITHOUT `payment_method`: the response carries a short-lived
     * `client_secret` for the browser payment element, where the buyer picks the
     * method. The secret is on this response only. The server refuses a wrong
     * `payment_method` / `ui_mode` combination with a 400.
     *
     * `refund_window_days` is how long the payment stays refundable: `0`
     * disables refunds, the default is `30`, the maximum `365`. The payment
     * settles through the `one_shot_payment.succeeded` /
     * `one_shot_payment.failed` webhooks; `banktransfer` takes days, not
     * seconds. Refund one with `$client->refunds->create(['one_shot_payment_id'
     * => $id])`.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return OneShotPayment
     */
    public function create(array $params = [], array $opts = []): OneShotPayment
    {
        return $this->postObject('/v1/checkout/one_shot', $params, $opts, OneShotPayment::class);
    }

    /**
     * One one-shot payment.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return OneShotPayment
     */
    public function retrieve(string $id, array $params = [], array $opts = []): OneShotPayment
    {
        return $this->getObject('/v1/checkout/one_shot/' . self::p($id), $params, $opts, OneShotPayment::class);
    }

    /**
     * One page of one-shot payments, newest first.
     *
     * `customer_id` narrows to one buyer and `status` to one of `open`,
     * `pending`, `authorized`, `paid`, `failed`, `canceled`, `expired` or
     * `refunded`. Failed, expired and still-open charges are listed, so read
     * `status` before treating a row as revenue. Subscription payments are in
     * {@see Payments::all()}.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return Collection<OneShotPayment>
     */
    public function all(array $params = [], array $opts = []): Collection
    {
        return $this->getList('/v1/checkout/one_shot', $params, $opts, OneShotPayment::class);
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
     * @return \Generator<int, OneShotPayment>
     */
    public function autoPagingIterator(array $params = [], array $opts = []): \Generator
    {
        yield from $this->all($params, $opts)->autoPagingIterator();
    }
}
