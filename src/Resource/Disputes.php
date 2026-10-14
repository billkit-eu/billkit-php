<?php

declare(strict_types=1);

namespace BillKit\Resource;

use BillKit\Collection;
use BillKit\Model\Dispute;

/**
 * Disputes (chargebacks) raised against payments.
 *
 * @internal constructed by {@see \BillKit\BillKitClient}; use `$client->disputes`.
 */
final class Disputes extends BaseResource
{
    /**
     * One dispute.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return Dispute
     */
    public function retrieve(string $id, array $params = [], array $opts = []): Dispute
    {
        return $this->getObject('/v1/disputes/' . self::p($id), $params, $opts, Dispute::class);
    }

    /**
     * One page of disputes.
     *
     * `status` takes a list of `open` / `won` (`['open', 'won']`, sent
     * comma-joined) or one string; there is no
     * `lost`, because the provider gives no signal for one. `payment_id` matches
     * subscription payments only, not one-off charges.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return Collection<Dispute>
     */
    public function all(array $params = [], array $opts = []): Collection
    {
        return $this->getList('/v1/disputes', $params, $opts, Dispute::class);
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
     * @return \Generator<int, Dispute>
     */
    public function autoPagingIterator(array $params = [], array $opts = []): \Generator
    {
        yield from $this->all($params, $opts)->autoPagingIterator();
    }
}
