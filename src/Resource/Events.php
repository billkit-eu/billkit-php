<?php

declare(strict_types=1);

namespace BillKit\Resource;

use BillKit\Collection;
use BillKit\Model\Event;

/**
 * Events: the account's activity log, the same objects webhooks deliver.
 *
 * @internal constructed by {@see \BillKit\BillKitClient}; use `$client->events`.
 */
final class Events extends BaseResource
{
    /**
     * One event, decoded into its typed class (`SubscriptionUpdatedEvent` for
     * `subscription.updated`) or a plain {@see Event} for a type this SDK
     * version does not know.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return Event
     */
    public function retrieve(string $id, array $params = [], array $opts = []): Event
    {
        return $this->getObject('/v1/events/' . self::p($id), $params, $opts, Event::class);
    }

    /**
     * One page of events, newest first.
     *
     * `type` filters server-side: one type (`'customer.created'`) or a list
     * (`['invoice.paid', 'invoice.payment_failed']`), sent comma-joined. `['expand' =>
     * ['customer']]` names the customer an event is about, so an activity feed
     * does not render an id nobody can read.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return Collection<Event>
     */
    public function all(array $params = [], array $opts = []): Collection
    {
        return $this->getList('/v1/events', $params, $opts, Event::class);
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
     * @return \Generator<int, Event>
     */
    public function autoPagingIterator(array $params = [], array $opts = []): \Generator
    {
        yield from $this->all($params, $opts)->autoPagingIterator();
    }
}
