<?php

declare(strict_types=1);

namespace BillKit\Resource;

use BillKit\Model\BillingPortalSession;
use BillKit\Model\BillingPortalSessionRevoke;

/**
 * Customer billing-portal sessions.
 *
 * @internal constructed by {@see \BillKit\BillKitClient}; use `$client->billingPortalSessions`.
 */
final class BillingPortalSessions extends BaseResource
{
    /**
     * Mint a portal session for a subscription. The URL and its token are
     * returned once.
     *
     * `subscription_id` and `return_url` are required. `['deliver_email' =>
     * true]` also emails the portal link to the subscription's customer, at the
     * address on their record, as a tenant-branded message. It defaults to
     * `false`: without it you distribute the returned `url` yourself.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return BillingPortalSession
     */
    public function create(array $params = [], array $opts = []): BillingPortalSession
    {
        return $this->postObject('/v1/billing_portal/sessions', $params, $opts, BillingPortalSession::class);
    }

    /**
     * End a portal session that is still in the wild. Idempotent.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return BillingPortalSessionRevoke
     */
    public function revoke(string $id, array $params = [], array $opts = []): BillingPortalSessionRevoke
    {
        return $this->postAction('/v1/billing_portal/sessions/' . self::p($id) . '/revoke', $params, $opts, BillingPortalSessionRevoke::class);
    }
}
