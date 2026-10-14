<?php

declare(strict_types=1);

namespace BillKit\Resource;

use BillKit\Collection;
use BillKit\Model\Payment;
use BillKit\Model\ProviderPayload;

/**
 * Subscription payments.
 *
 * @internal constructed by {@see \BillKit\BillKitClient}; use `$client->payments`.
 */
final class Payments extends BaseResource
{
    /**
     * One payment.
     *
     * `['expand' => ['customer', 'subscription']]` attaches the buyer and the
     * subscription. `['expand' => ['refund_eligibility']]` attaches whether a
     * refund of the remaining balance would succeed now, applying the refund
     * window and the price's refund policy; when it would not, `reason` says
     * why. Treat a reason you do not recognise as "not refundable". That
     * relation is retrieve-only.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return Payment
     */
    public function retrieve(string $id, array $params = [], array $opts = []): Payment
    {
        return $this->getObject('/v1/payments/' . self::p($id), $params, $opts, Payment::class);
    }

    /**
     * The provider's own record of this charge, read live.
     *
     * It carries what BillKit deliberately does not keep (the card BIN, the
     * iDEAL bank, the provider's status string). A provider outage or a charge
     * old enough to have aged out answers with `available: false` and a short
     * reason rather than an error.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return ProviderPayload
     */
    public function retrieveProvider(string $id, array $params = [], array $opts = []): ProviderPayload
    {
        return $this->getObject('/v1/payments/' . self::p($id) . '/provider', $params, $opts, ProviderPayload::class);
    }

    /**
     * One page of payments.
     *
     * `customer_id` narrows to one buyer. Failed and pending attempts are listed
     * alongside settled ones, so read `status` before treating a row as revenue.
     * One-off charges are in {@see OneShotPayments::all()}. `['expand' =>
     * ['customer', 'subscription']]` is accepted.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return Collection<Payment>
     */
    public function all(array $params = [], array $opts = []): Collection
    {
        return $this->getList('/v1/payments', $params, $opts, Payment::class);
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
     * @return \Generator<int, Payment>
     */
    public function autoPagingIterator(array $params = [], array $opts = []): \Generator
    {
        yield from $this->all($params, $opts)->autoPagingIterator();
    }
}
