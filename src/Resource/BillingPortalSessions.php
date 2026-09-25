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
     * ``['deliver_email' => true]`` also emails the portal link to the
     * subscription's customer, at the address on their record, as a
     * tenant-branded message. It defaults to ``false``: without it you
     * distribute the returned ``url`` yourself.
     *
     * @param array<string, mixed> $params Requires ``subscription_id`` and
     *                                     ``return_url``; optional
     *                                     ``deliver_email``.
     *
     * @return array<string, mixed>
     */
    public function create(array $params): array
    {
        return $this->post('/v1/billing_portal/sessions', $params);
    }

    /**
     * Kill an in-the-wild portal session. Idempotent.
     *
     * @return array<string, mixed>
     */
    public function revoke(string $id, ?string $idempotencyKey = null): array
    {
        return $this->postEmpty('/v1/billing_portal/sessions/' . self::p($id) . '/revoke', $idempotencyKey);
    }
}
