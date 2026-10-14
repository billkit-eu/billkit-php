<?php

declare(strict_types=1);

namespace BillKit\Resource;

use BillKit\Collection;
use BillKit\Model\Refund;

/**
 * Refunds against subscription and one-shot payments.
 *
 * @internal constructed by {@see \BillKit\BillKitClient}; use `$client->refunds`.
 */
final class Refunds extends BaseResource
{
    /**
     * Refund a payment, a subscription's latest payment, or a one-shot payment.
     *
     * Pass exactly one of `payment_id`, `subscription_id` or
     * `one_shot_payment_id`. Add `amount_cents` for a partial refund; omit it to
     * refund the whole remaining balance. Refunds settle asynchronously: the
     * returned refund is usually `pending`, and `refund.succeeded` or
     * `refund.failed` follows. Pass an `idempotency_key` option derived from
     * your own order id, so a retry of your code cannot refund twice.
     *
     * A payment that is not `paid`, or is outside its refund window, is a 409
     * `ConflictException` with code `state_conflict`, as are the settled facts
     * `charge_already_refunded`, `charge_disputed` and `refund_duplicate`. An
     * amount above what is left is a 400.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return Refund
     */
    public function create(array $params = [], array $opts = []): Refund
    {
        return $this->postObject('/v1/refunds', $params, $opts, Refund::class);
    }

    /**
     * One refund.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return Refund
     */
    public function retrieve(string $id, array $params = [], array $opts = []): Refund
    {
        return $this->getObject('/v1/refunds/' . self::p($id), $params, $opts, Refund::class);
    }

    /**
     * One page of refunds.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return Collection<Refund>
     */
    public function all(array $params = [], array $opts = []): Collection
    {
        return $this->getList('/v1/refunds', $params, $opts, Refund::class);
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
     * @return \Generator<int, Refund>
     */
    public function autoPagingIterator(array $params = [], array $opts = []): \Generator
    {
        yield from $this->all($params, $opts)->autoPagingIterator();
    }
}
