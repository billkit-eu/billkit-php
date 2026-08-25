<?php

declare(strict_types=1);

namespace BillKit\Resource;

/**
 * Mint and revoke customer-facing billing-portal sessions.
 *
 * Each session token is scoped to a single subscription with a sliding
 * 30-minute idle window and a 2-hour hard cap. The raw token is returned
 * **once** on mint, alongside the embeddable URL.
 */
final class BillingPortalSessions extends BaseResource
{
    /**
     * Mint a portal session for a subscription; the raw token + URL are
     * returned once.
     *
     * @param array<string, mixed> $params Requires ``subscription_id`` and ``return_url``.
     *
     * @return array<string, mixed>
     */
    public function create(array $params): array
    {
        return $this->postFixed(
            '/v1/billing_portal/sessions',
            [
                'subscription_id' => $params['subscription_id'] ?? null,
                'return_url' => $params['return_url'] ?? null,
            ],
            $this->idempotencyKeyOf($params),
        );
    }

    /**
     * Kill an in-the-wild portal session. Idempotent.
     *
     * @return array<string, mixed>
     */
    public function revoke(string $id, ?string $idempotencyKey = null): array
    {
        return $this->postEmpty("/v1/billing_portal/sessions/{$id}/revoke", $idempotencyKey);
    }
}
