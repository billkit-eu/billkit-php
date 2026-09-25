<?php

declare(strict_types=1);

namespace BillKit\Resource;

/** Hosted / embedded checkout sessions. */
final class CheckoutSessions extends BaseResource
{
    /**
     * Create a checkout session for a customer + price.
     *
     * ``country`` is the buyer's ISO-3166-1 alpha-2 country, when you
     * already know it. It is stored on the customer if they do not have one
     * yet, which is what lets VAT apply to the **first** charge: on the
     * hosted flow the buyer only reaches a country-collecting page after
     * the charge exists. It never overwrites a country the customer
     * already has.
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
        return $this->get('/v1/checkout/sessions/' . self::p($id));
    }
}
