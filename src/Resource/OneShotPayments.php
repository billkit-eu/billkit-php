<?php

declare(strict_types=1);

namespace BillKit\Resource;

/**
 * Mandate-less, one-off payments (Stripe PaymentIntent shape).
 *
 * A one-shot payment charges a customer a single time without creating a
 * reusable mandate: there is no subscription, no renewal, and nothing is
 * stored for future off-session use. Use it for one-time purchases where a
 * hosted redirect flow is acceptable.
 */
final class OneShotPayments extends BaseResource
{
    /**
     * Create a one-shot (mandate-less) payment and get a redirect URL.
     *
     * The created object has ``"object": "one_shot_payment"`` and a
     * ``redirect_url``. Send the shopper there to complete the payment.
     * Because no mandate is created, the charge cannot be replayed later.
     *
     * ``refund_window_days`` controls how long the payment stays refundable:
     * ``0`` disables refunds entirely, the default is ``30``, and the maximum
     * is ``365``. It reaches a terminal state via the
     * ``one_shot_payment.succeeded`` / ``one_shot_payment.failed`` webhooks.
     *
     * To refund a settled one-shot payment, call
     * ``$client->refunds->create(['one_shot_payment_id' => $id])``.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function create(array $params): array
    {
        return $this->post('/v1/checkout/one_shot', $params);
    }

    /**
     * Fetch a single one-shot payment by id.
     *
     * @return array<string, mixed>
     */
    public function retrieve(string $id): array
    {
        return $this->get("/v1/checkout/one_shot/{$id}");
    }
}
