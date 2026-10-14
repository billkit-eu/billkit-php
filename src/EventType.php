<?php

declare(strict_types=1);

namespace BillKit;

/**
 * Every event type BillKit can deliver, as constants.
 *
 * Maintained by hand from the API's event catalogue, and checked against it
 * in both directions when the SDK is built, so a type the API adds or drops
 * cannot ship unnoticed. Use them to subscribe an endpoint and to route a
 * delivery without a typo that would never match:
 *
 *     if ($event->type === EventType::INVOICE_PAID) { ... }
 *
 * The list describes the API this SDK version was built against, nothing
 * more. A newer API can deliver a type this list does not name, so a webhook
 * handler must treat an unknown ``type`` as "ignore", never as an error.
 * Nothing in the SDK rejects one: {@see Webhooks::verifySignature()} decodes
 * a known type into its typed event class and any other into a plain
 * {@see Model\Event}.
 */
final class EventType
{
    // Checkout
    public const CHECKOUT_SESSION_COMPLETED = 'checkout.session.completed';
    public const CHECKOUT_SESSION_EXPIRED = 'checkout.session.expired';

    // Customer
    public const CUSTOMER_CREATED = 'customer.created';
    public const CUSTOMER_UPDATED = 'customer.updated';
    public const CUSTOMER_DELETED = 'customer.deleted';

    // Subscription lifecycle
    public const SUBSCRIPTION_CREATED = 'subscription.created';
    public const SUBSCRIPTION_UPDATED = 'subscription.updated';
    public const SUBSCRIPTION_CANCELED = 'subscription.canceled';
    public const SUBSCRIPTION_PAST_DUE = 'subscription.past_due';
    public const SUBSCRIPTION_PAUSED = 'subscription.paused';
    public const SUBSCRIPTION_RESUMED = 'subscription.resumed';
    public const SUBSCRIPTION_REACTIVATED = 'subscription.reactivated';
    public const SUBSCRIPTION_PLAN_CHANGED = 'subscription.plan_changed';
    public const SUBSCRIPTION_TRIAL_STARTED = 'subscription.trial_started';
    public const SUBSCRIPTION_TRIAL_WILL_END = 'subscription.trial_will_end';
    public const SUBSCRIPTION_TRIAL_ENDED = 'subscription.trial_ended';
    public const SUBSCRIPTION_PAYMENT_METHOD_UPDATED = 'subscription.payment_method_updated';
    public const SUBSCRIPTION_COUPON_APPLIED = 'subscription.coupon_applied';
    public const SUBSCRIPTION_COUPON_EXPIRED = 'subscription.coupon_expired';

    // Payments
    public const PAYMENT_SUCCEEDED = 'payment.succeeded';
    public const PAYMENT_FAILED = 'payment.failed';
    public const ONE_SHOT_PAYMENT_SUCCEEDED = 'one_shot_payment.succeeded';
    public const ONE_SHOT_PAYMENT_FAILED = 'one_shot_payment.failed';
    public const ONE_SHOT_PAYMENT_REFUNDED = 'one_shot_payment.refunded';

    // Refunds and disputes
    public const REFUND_CREATED = 'refund.created';
    public const REFUND_SUCCEEDED = 'refund.succeeded';
    public const REFUND_FAILED = 'refund.failed';
    public const DISPUTE_CREATED = 'dispute.created';
    public const DISPUTE_CLOSED = 'dispute.closed';

    // Documents
    public const INVOICE_CREATED = 'invoice.created';
    public const INVOICE_UPCOMING = 'invoice.upcoming';
    public const INVOICE_PAID = 'invoice.paid';
    public const INVOICE_PAYMENT_FAILED = 'invoice.payment_failed';
    public const INVOICE_VOIDED = 'invoice.voided';
    public const INVOICE_MARKED_UNCOLLECTIBLE = 'invoice.marked_uncollectible';
    public const CREDIT_NOTE_CREATED = 'credit_note.created';

    // Catalogue
    public const PRODUCT_CREATED = 'product.created';
    public const PRODUCT_UPDATED = 'product.updated';
    public const PRODUCT_ARCHIVED = 'product.archived';
    public const PRICE_CREATED = 'price.created';
    public const PRICE_UPDATED = 'price.updated';
    public const PRICE_ARCHIVED = 'price.archived';
    public const COUPON_CREATED = 'coupon.created';
    public const COUPON_UPDATED = 'coupon.updated';

    // Endpoint management
    public const WEBHOOK_ENDPOINT_CREATED = 'webhook_endpoint.created';
    public const WEBHOOK_ENDPOINT_UPDATED = 'webhook_endpoint.updated';
    public const WEBHOOK_ENDPOINT_DELETED = 'webhook_endpoint.deleted';

    // Operational
    public const DUNNING_EMAIL_REQUIRED = 'dunning.email_required';
    private function __construct()
    {
    }

    /**
     * Every event type constant's value.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        /** @var list<string> $values */
        $values = array_values((new \ReflectionClass(self::class))->getConstants());

        return $values;
    }

    /**
     * Whether this SDK version knows ``$type``. ``false`` is not an error: it
     * usually means the API is newer than the SDK.
     */
    public static function isKnown(string $type): bool
    {
        return in_array($type, self::all(), true);
    }
}
