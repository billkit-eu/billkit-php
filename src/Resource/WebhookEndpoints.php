<?php

declare(strict_types=1);

namespace BillKit\Resource;

use BillKit\Collection;
use BillKit\Model\DeletedResource;
use BillKit\Model\EventTypeList;
use BillKit\Model\WebhookDelivery;
use BillKit\Model\WebhookEndpoint;

/**
 * Webhook endpoints and their delivery log.
 *
 * @internal constructed by {@see \BillKit\BillKitClient}; use `$client->webhookEndpoints`.
 */
final class WebhookEndpoints extends BaseResource
{
    /**
     * Every event type this deployment can deliver, plus the wildcard.
     * `enabled_events` refuses anything not on this list, so read it rather than
     * hard-coding a set.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return EventTypeList
     */
    public function listEventTypes(array $params = [], array $opts = []): EventTypeList
    {
        return $this->getObject('/v1/webhook_endpoints/event_types', $params, $opts, EventTypeList::class);
    }

    /**
     * Register an endpoint. The signing secret (`bkwhsec_...`) is on this
     * response only; store it.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return WebhookEndpoint
     */
    public function create(array $params = [], array $opts = []): WebhookEndpoint
    {
        return $this->postObject('/v1/webhook_endpoints', $params, $opts, WebhookEndpoint::class);
    }

    /**
     * One endpoint.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return WebhookEndpoint
     */
    public function retrieve(string $id, array $params = [], array $opts = []): WebhookEndpoint
    {
        return $this->getObject('/v1/webhook_endpoints/' . self::p($id), $params, $opts, WebhookEndpoint::class);
    }

    /**
     * Change an endpoint's URL, events, description or status.
     *
     * `['status' => 'disabled']` stops delivery and keeps the endpoint, its
     * secret and its history; `'enabled'` resumes. `['description' => null]` is
     * sent as an explicit JSON null and removes the description; other `null`
     * values are dropped.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return WebhookEndpoint
     */
    public function update(string $id, array $params = [], array $opts = []): WebhookEndpoint
    {
        return $this->postObject('/v1/webhook_endpoints/' . self::p($id), $params, $opts, WebhookEndpoint::class, ['description']);
    }

    /**
     * Delete an endpoint. Returns a {@see DeletedResource}. It 404s afterwards
     * and its delivery attempts go with it; the events themselves are untouched.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return DeletedResource
     */
    public function delete(string $id, array $params = [], array $opts = []): DeletedResource
    {
        return $this->deleteObject('/v1/webhook_endpoints/' . self::p($id), $params, $opts, DeletedResource::class);
    }

    /**
     * Rotate the signing secret. The new secret is on this response only.
     * Deliveries are signed with both secrets for a while, so a receiver can
     * switch without dropping one.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return WebhookEndpoint
     */
    public function rotateSecret(string $id, array $params = [], array $opts = []): WebhookEndpoint
    {
        return $this->postAction('/v1/webhook_endpoints/' . self::p($id) . '/rotate_secret', $params, $opts, WebhookEndpoint::class);
    }

    /**
     * One page of endpoints.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return Collection<WebhookEndpoint>
     */
    public function all(array $params = [], array $opts = []): Collection
    {
        return $this->getList('/v1/webhook_endpoints', $params, $opts, WebhookEndpoint::class);
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
     * @return \Generator<int, WebhookEndpoint>
     */
    public function autoPagingIterator(array $params = [], array $opts = []): \Generator
    {
        yield from $this->all($params, $opts)->autoPagingIterator();
    }

    /**
     * One page of delivery attempts for one endpoint.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return Collection<WebhookDelivery>
     */
    public function listDeliveries(string $endpointId, array $params = [], array $opts = []): Collection
    {
        return $this->getList('/v1/webhook_endpoints/' . self::p($endpointId) . '/deliveries', $params, $opts, WebhookDelivery::class);
    }

    /**
     * Every delivery attempt for one endpoint, across all pages. Takes the same
     * `$params` as {@see self::listDeliveries()}.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return \Generator<int, WebhookDelivery>
     */
    public function autoPagingIteratorDeliveries(string $endpointId, array $params = [], array $opts = []): \Generator
    {
        yield from $this->listDeliveries($endpointId, $params, $opts)->autoPagingIterator();
    }

    /**
     * One delivery attempt.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return WebhookDelivery
     */
    public function retrieveDelivery(string $endpointId, string $deliveryId, array $params = [], array $opts = []): WebhookDelivery
    {
        return $this->getObject('/v1/webhook_endpoints/' . self::p($endpointId) . '/deliveries' . '/' . self::p($deliveryId), $params, $opts, WebhookDelivery::class);
    }

    /**
     * Queue a delivery again. Idempotent: one already delivered comes back
     * unchanged.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return WebhookDelivery
     */
    public function redeliver(string $endpointId, string $deliveryId, array $params = [], array $opts = []): WebhookDelivery
    {
        return $this->postAction('/v1/webhook_endpoints/' . self::p($endpointId) . '/deliveries' . '/' . self::p($deliveryId) . '/redeliver', $params, $opts, WebhookDelivery::class);
    }
}
