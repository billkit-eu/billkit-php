<?php

declare(strict_types=1);

namespace BillKit\Resource;

use BillKit\Collection;

/** Manage webhook endpoints, signing secrets, and delivery records. */
final class WebhookEndpoints extends BaseResource
{
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
        return $this->get("/v1/webhook_endpoints/{$id}");
    }

    /**
     * Patch mutable fields (url, enabled events, status) on an endpoint.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function update(string $id, array $params): array
    {
        return $this->post("/v1/webhook_endpoints/{$id}", $params);
    }

    /**
     * Delete a webhook endpoint.
     *
     * @return array<string, mixed>
     */
    public function delete(string $id, ?string $idempotencyKey = null): array
    {
        return $this->del("/v1/webhook_endpoints/{$id}", $idempotencyKey);
    }

    /**
     * Rotate the signing secret. The new ``whsec_...`` is returned once.
     *
     * @return array<string, mixed>
     */
    public function rotateSecret(string $id, ?string $idempotencyKey = null): array
    {
        return $this->postEmpty("/v1/webhook_endpoints/{$id}/rotate_secret", $idempotencyKey);
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
        return $this->get("/v1/webhook_endpoints/{$endpointId}/deliveries", $params);
    }

    /**
     * Yield every delivery record for one endpoint across all pages.
     *
     * @return \Generator<int, mixed>
     */
    public function autoPagingIteratorDeliveries(string $endpointId, ?int $pageSize = null): \Generator
    {
        yield from Collection::autoPagingIterator(
            fn (array $p): array => $this->get("/v1/webhook_endpoints/{$endpointId}/deliveries", $p),
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
        return $this->get("/v1/webhook_endpoints/{$endpointId}/deliveries/{$deliveryId}");
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
            "/v1/webhook_endpoints/{$endpointId}/deliveries/{$deliveryId}/redeliver",
            $idempotencyKey,
        );
    }
}
