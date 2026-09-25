<?php

declare(strict_types=1);

namespace BillKit\Resource;

use BillKit\Collection;

/** Manage webhook endpoints, signing secrets, and delivery records. */
final class WebhookEndpoints extends BaseResource
{
    /**
     * Every event type this deployment can deliver, plus the wildcard.
     *
     * ``enabled_events`` rejects anything not on this list, so read it
     * rather than hard-coding a set: a name that is not on it fails at
     * registration and leaves you with an endpoint that never fires.
     *
     * @return array<string, mixed>
     */
    public function listEventTypes(): array
    {
        return $this->get('/v1/webhook_endpoints/event_types');
    }

    /**
     * Register a webhook endpoint.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function create(array $params): array
    {
        return $this->post('/v1/webhook_endpoints', $params);
    }

    /**
     * Fetch a single webhook endpoint by id.
     *
     * @return array<string, mixed>
     */
    public function retrieve(string $id): array
    {
        return $this->get('/v1/webhook_endpoints/' . self::p($id));
    }

    /**
     * Patch mutable fields (url, enabled events, status) on an endpoint.
     *
     * `['status' => 'disabled']` stops delivery and keeps the endpoint,
     * its signing secret and its delivery history; `'enabled'` resumes.
     * Use {@see self::delete()} when the endpoint should not exist at
     * all: disabling is reversible and deleting is not.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function update(string $id, array $params): array
    {
        return $this->post('/v1/webhook_endpoints/' . self::p($id), $params);
    }

    /**
     * Delete an endpoint. Returns `['id', 'object', 'deleted' => true]`.
     *
     * A URL registered by mistake should not be a permanent fixture of
     * the account, so this removes it: {@see self::retrieve()} 404s
     * afterwards and it is gone from {@see self::all()}. Its delivery
     * attempts go with it, because they are readable only through the
     * endpoint that owns them. The events themselves are untouched, so
     * what you were sent stays on record.
     *
     * @return array<string, mixed>
     */
    public function delete(string $id, ?string $idempotencyKey = null): array
    {
        return $this->del('/v1/webhook_endpoints/' . self::p($id), $idempotencyKey);
    }

    /**
     * Rotate the signing secret. The new ``bkwhsec_...`` is returned once.
     *
     * @return array<string, mixed>
     */
    public function rotateSecret(string $id, ?string $idempotencyKey = null): array
    {
        return $this->postEmpty('/v1/webhook_endpoints/' . self::p($id) . '/rotate_secret', $idempotencyKey);
    }

    /**
     * List one page of webhook endpoints. Use {@see self::autoPagingIterator()}
     * to walk every page.
     *
     * @param array<string, scalar|null> $params
     *
     * @return array<string, mixed>
     */
    public function all(array $params = []): array
    {
        return $this->get('/v1/webhook_endpoints', $params);
    }

    /**
     * Yield every webhook endpoint across all pages.
     *
     * @return \Generator<int, mixed>
     */
    public function autoPagingIterator(?int $pageSize = null): \Generator
    {
        yield from Collection::autoPagingIterator(
            fn (array $p): array => $this->get('/v1/webhook_endpoints', $p),
            $pageSize,
        );
    }

    /**
     * List one page of per-attempt delivery records for one endpoint.
     *
     * @param array<string, scalar|null> $params
     *
     * @return array<string, mixed>
     */
    public function allDeliveries(string $endpointId, array $params = []): array
    {
        return $this->get('/v1/webhook_endpoints/' . self::p($endpointId) . '/deliveries', $params);
    }

    /**
     * Yield every delivery record for one endpoint across all pages.
     *
     * @return \Generator<int, mixed>
     */
    public function autoPagingIteratorDeliveries(string $endpointId, ?int $pageSize = null): \Generator
    {
        yield from Collection::autoPagingIterator(
            fn (array $p): array => $this->get('/v1/webhook_endpoints/' . self::p($endpointId) . '/deliveries', $p),
            $pageSize,
        );
    }

    /**
     * Fetch one delivery record for inspection.
     *
     * @return array<string, mixed>
     */
    public function retrieveDelivery(string $endpointId, string $deliveryId): array
    {
        return $this->get('/v1/webhook_endpoints/' . self::p($endpointId) . '/deliveries/' . self::p($deliveryId));
    }

    /**
     * Re-enqueue a delivery row for the dispatcher. Idempotent: a row
     * already ``delivered`` returns unchanged.
     *
     * @return array<string, mixed>
     */
    public function redeliver(string $endpointId, string $deliveryId, ?string $idempotencyKey = null): array
    {
        return $this->postEmpty(
            '/v1/webhook_endpoints/' . self::p($endpointId) . '/deliveries/' . self::p($deliveryId) . '/redeliver',
            $idempotencyKey,
        );
    }
}
