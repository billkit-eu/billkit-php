<?php

declare(strict_types=1);

namespace BillKit\Resource;

use BillKit\Model\CheckoutSession;

/**
 * Hosted and embedded checkout for a subscription.
 *
 * @internal constructed by {@see \BillKit\BillKitClient}; use `$client->checkoutSessions`.
 */
final class CheckoutSessions extends BaseResource
{
    /**
     * Create a checkout session for a customer and a price.
     *
     * The subscription is created when the buyer authorises payment, not by this
     * call; it arrives as `subscription.created`.
     *
     * `country` is the buyer's ISO 3166-1 alpha-2 country, when you already know
     * it. It is stored on the customer if they do not have one yet, which is
     * what lets VAT apply to the **first** charge: on the hosted flow the buyer
     * only reaches a country-collecting page after the charge exists. It never
     * overwrites a country the customer already has.
     *
     * `payment_method` pins the Mollie method (`creditcard`, `directdebit`,
     * `ideal`, `eps`, `applepay`, `paypal`); leave it out to let the buyer pick.
     *
     * `'ui_mode' => 'embedded'` returns a `client_secret` instead of a `url`,
     * for `@billkit-eu/js` or `@billkit-eu/react`, and must be sent without
     * `payment_method`: the buyer picks it inside the element.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return CheckoutSession
     */
    public function create(array $params = [], array $opts = []): CheckoutSession
    {
        return $this->postObject('/v1/checkout/sessions', $params, $opts, CheckoutSession::class);
    }

    /**
     * One checkout session.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return CheckoutSession
     */
    public function retrieve(string $id, array $params = [], array $opts = []): CheckoutSession
    {
        return $this->getObject('/v1/checkout/sessions/' . self::p($id), $params, $opts, CheckoutSession::class);
    }
}
