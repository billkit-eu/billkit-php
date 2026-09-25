<?php

declare(strict_types=1);

namespace BillKit\Resource;

use BillKit\Collection;

/** Tenant-scoped buyer records. */
final class Customers extends BaseResource
{
    /**
     * Create a customer.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function create(array $params = []): array
    {
        return $this->post('/v1/customers', $params);
    }

    /**
     * Fetch a single customer by id.
     *
     * @return array<string, mixed>
     */
    public function retrieve(string $id): array
    {
        return $this->get('/v1/customers/' . self::p($id));
    }

    /**
     * Patch mutable fields on a customer.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function update(string $id, array $params = []): array
    {
        return $this->post('/v1/customers/' . self::p($id), $params);
    }

    /**
     * Delete a customer. Returns `['id', 'object', 'deleted' => true]`.
     *
     * The customer leaves the API: {@see self::retrieve()} 404s and they
     * drop out of {@see self::all()}, which is why the response is a
     * marker and not the customer. Their payments, invoices and refunds
     * are untouched, and so is their personal data — {@see self::purge()}
     * is the GDPR erasure. Refused while they hold a subscription that
     * can still charge them.
     *
     * @return array<string, mixed>
     */
    public function delete(string $id, ?string $idempotencyKey = null): array
    {
        return $this->del('/v1/customers/' . self::p($id), $idempotencyKey);
    }

    /**
     * List one page of customers, newest first. Use
     * {@see self::autoPagingIterator()} to walk every page.
     *
     * ``provisional`` filters on whether the customer ever completed a
     * payment. A checkout that captures an email commits its Customer before
     * the charge, so a checkout nobody finished leaves a row behind: pass
     * ``false`` for real customers only, ``true`` for the abandoned ones (the
     * cart-recovery worklist), or omit for both. Abandoned rows are swept
     * after the tenant's retention window.
     *
     * ``['expand' => ['stats']]`` attaches each customer's lifetime totals.
     * ``stats`` is the only relation this route expands; anything else is a
     * ``400`` naming it.
     *
     * @param array<string, scalar|list<string>|null> $params
     *
     * @return array<string, mixed>
     */
    public function all(array $params = []): array
    {
        return $this->get('/v1/customers', $params);
    }

    /**
     * Yield every customer across all pages.
     *
     * @return \Generator<int, mixed>
     */
    public function autoPagingIterator(?int $pageSize = null): \Generator
    {
        yield from Collection::autoPagingIterator(
            fn (array $p): array => $this->get('/v1/customers', $p),
            $pageSize,
        );
    }

    /**
     * Attach, replace, or clear the customer's VAT number; triggers
     * server-side VIES validation. The response carries
     * ``vat_number_validated``.
     *
     * ``['vat_number' => null]`` **clears** the registration and is sent as
     * an explicit JSON null rather than stripped, one of the few places in
     * this SDK where a ``null`` in a params array is a value rather than an
     * omission. VIES needs a country, so pass ``country_code`` when the
     * customer does not have one yet; that one is still dropped when null.
     *
     * Because a null here is a value, the key has to be present: an array
     * without ``vat_number`` is refused rather than read as a clear, which is
     * what an absent key would otherwise become. Node and python make the
     * field required; this is the same rule for a language without one.
     *
     * @param array<string, mixed> $params ``vat_number`` (``null`` clears),
     *                                     optional ``country_code``, optional
     *                                     ``idempotency_key``
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException when ``vat_number`` is absent
     */
    public function setVatNumber(string $id, array $params): array
    {
        if (!array_key_exists('vat_number', $params)) {
            throw new \InvalidArgumentException(
                'setVatNumber() needs a vat_number key: a string sets the registration and an explicit null clears it.'
            );
        }
        $body = ['vat_number' => $params['vat_number']];
        if (($params['country_code'] ?? null) !== null) {
            $body['country_code'] = $params['country_code'];
        }

        return $this->postFixed(
            '/v1/customers/' . self::p($id) . '/vat_number',
            $body,
            $this->idempotencyKeyOf($params),
        );
    }

    /**
     * Hard-purge a customer's PII for GDPR erasure (irreversible;
     * {@see self::delete()} removes the customer from the API but leaves
     * their personal data in place). The server requires
     * ``confirmed: true`` as a fat-finger guard; defaulted to ``true``
     * here so callers don't opt in twice.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function purge(string $id, array $params = []): array
    {
        $confirmed = $params['confirmed'] ?? true;

        return $this->postFixed(
            '/v1/customers/' . self::p($id) . '/purge',
            ['confirmed' => $confirmed],
            $this->idempotencyKeyOf($params),
        );
    }
}
