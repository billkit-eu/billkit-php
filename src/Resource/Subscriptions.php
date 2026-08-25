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
     * @return \Generator<int, mixed>
     */
    public function autoPagingIterator(?int $pageSize = null): \Generator
    {
        yield from Collection::autoPagingIterator(
            fn (array $p): array => $this->get('/v1/subscriptions', $p),
            $pageSize,
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
}
