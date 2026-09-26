<?php

declare(strict_types=1);

namespace BillKit\Resource;

use BillKit\Collection;

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
        return $this->get('/v1/checkout/one_shot/' . self::p($id));
    }

    /**
     * List one page of one-shot payments, newest first. Use
     * {@see self::autoPagingIterator()} to walk every page.
     *
     * ``customer_id`` narrows to one buyer's charges and ``status`` to one
     * of ``open``, ``pending``, ``authorized``, ``paid``, ``failed``,
     * ``canceled``, ``expired`` or ``refunded``; any other status throws
     * {@see \BillKit\Exception\InvalidRequestException}. Failed, expired
     * and still-open charges are listed, so read ``status`` before treating
     * a row as revenue. Subscription payments are not here; they are in
     * {@see Payments::all()}.
     *
     * @param array<string, scalar|list<string>|null> $params
     *
     * @return array<string, mixed>
     */
    public function all(array $params = []): array
    {
        return $this->get('/v1/checkout/one_shot', $params);
    }

    /**
     * Yield every one-shot payment across all pages. Both filters are
     * carried onto every page request.
     *
     * @return \Generator<int, mixed>
     */
    public function autoPagingIterator(
        ?int $pageSize = null,
        ?string $customerId = null,
        ?string $status = null,
    ): \Generator {
        yield from Collection::autoPagingIterator(
            fn (array $p): array => $this->get('/v1/checkout/one_shot', $p),
            $pageSize,
            ['customer_id' => $customerId, 'status' => $status],
        );
    }
}
