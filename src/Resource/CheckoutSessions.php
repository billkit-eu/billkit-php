<?php

declare(strict_types=1);

namespace BillKit\Resource;

/** Hosted / embedded checkout sessions. */
final class CheckoutSessions extends BaseResource
{
    /**
     * Create a checkout session for a customer + price.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function create(array $params): array
    {
        return $this->post('/v1/checkout/sessions', $params);
    }

    /**
     * Fetch a single checkout session by id.
     *
     * @return array<string, mixed>
     */
    public function retrieve(string $id): array
    {
        return $this->get("/v1/checkout/sessions/{$id}");
    }
}
