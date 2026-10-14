<?php

declare(strict_types=1);

namespace BillKit\Resource;

use BillKit\Collection;
use BillKit\Model\Customer;
use BillKit\Model\DeletedResource;
use BillKit\Model\PurgedResource;

/**
 * Customers: the buyers in your account.
 *
 * @internal constructed by {@see \BillKit\BillKitClient}; use `$client->customers`.
 */
final class Customers extends BaseResource
{
    /**
     * Create a customer.
     *
     * BillKit never deduplicates customers by email: two calls with the same
     * address create two customers. Look one up first if that matters to you.
     *
     * `test_clock_id` (test mode only) attaches the customer to a test clock, so
     * its subscriptions follow that clock's time. It is fixed at creation.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return Customer
     */
    public function create(array $params = [], array $opts = []): Customer
    {
        return $this->postObject('/v1/customers', $params, $opts, Customer::class);
    }

    /**
     * One customer.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return Customer
     */
    public function retrieve(string $id, array $params = [], array $opts = []): Customer
    {
        return $this->getObject('/v1/customers/' . self::p($id), $params, $opts, Customer::class);
    }

    /**
     * Change a customer. Only the keys you send change.
     *
     * `['name' => null]` is sent as an explicit JSON null and removes the name;
     * leave the key out to keep it. Other `null` values are dropped.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return Customer
     */
    public function update(string $id, array $params = [], array $opts = []): Customer
    {
        return $this->postObject('/v1/customers/' . self::p($id), $params, $opts, Customer::class, ['name']);
    }

    /**
     * Delete a customer. Returns a {@see DeletedResource}, not the customer.
     *
     * The customer leaves the API: {@see self::retrieve()} 404s and they drop
     * out of {@see self::all()}. Their payments, invoices and refunds are
     * untouched, and so is their personal data; {@see self::purge()} is the GDPR
     * erasure. Refused while they hold a subscription that can still charge
     * them.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return DeletedResource
     */
    public function delete(string $id, array $params = [], array $opts = []): DeletedResource
    {
        return $this->deleteObject('/v1/customers/' . self::p($id), $params, $opts, DeletedResource::class);
    }

    /**
     * Attach, replace or clear the customer's VAT number, validated against
     * VIES. The response carries `vat_number_validated`.
     *
     * `['vat_number' => null]` **clears** the registration and is sent as an
     * explicit JSON null. Because a null here is a value, the key has to be
     * present: an array without `vat_number` is refused rather than read as a
     * clear. VIES needs a country, so pass `country_code` when the customer does
     * not have one yet.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return Customer
     *
     * @throws \InvalidArgumentException when `vat_number` is absent
     */
    public function setVatNumber(string $id, array $params = [], array $opts = []): Customer
    {
        if (!array_key_exists('vat_number', $params)) {
            throw new \InvalidArgumentException(
                'setVatNumber() needs a vat_number key: a string sets the registration and an explicit null clears it.',
            );
        }
        $body = self::body($params);
        $body['vat_number'] = $params['vat_number'];

        return $this->postFixed('/v1/customers/' . self::p($id) . '/vat_number', $body, $opts, Customer::class);
    }

    /**
     * Erase a customer's personal data (GDPR Article 17). Irreversible.
     *
     * The confirmation is required: pass `['confirmed' => true]`. Without it
     * this throws `\InvalidArgumentException` before any request is made, so a
     * stray call cannot erase anyone. (In 0.x the SDK sent the confirmation for
     * you; it no longer does.)
     *
     * {@see self::delete()} removes a customer from the API but keeps their
     * personal data; this keeps the financial records and erases the person:
     * name, email, address, VAT registration, and the personal data inside their
     * event log.
     *
     * The answer is the deletion marker {@see self::delete()} returns plus
     * `purged`: `{id, object: "customer", deleted: true, purged: true}`. Not the
     * customer, whose erased placeholder email would read as live data; it 404s
     * afterwards.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return PurgedResource
     *
     * @throws \InvalidArgumentException when `confirmed` is not `true`
     */
    public function purge(string $id, array $params = [], array $opts = []): PurgedResource
    {
        if (($params['confirmed'] ?? null) !== true) {
            throw new \InvalidArgumentException(
                "purge() erases the customer's personal data and cannot be undone. "
                . "Pass ['confirmed' => true] to confirm that is what you mean.",
            );
        }

        return $this->postObject('/v1/customers/' . self::p($id) . '/purge', $params, $opts, PurgedResource::class);
    }

    /**
     * One page of customers, newest first.
     *
     * `provisional` filters on whether the customer ever completed a payment:
     * `false` for real customers only, `true` for checkouts nobody finished (the
     * cart-recovery worklist). `['expand' =>
     * ['stats']]` attaches lifetime totals.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return Collection<Customer>
     */
    public function all(array $params = [], array $opts = []): Collection
    {
        return $this->getList('/v1/customers', $params, $opts, Customer::class);
    }

    /**
     * Every item across all pages.
     *
     * Takes the same `$params` as {@see self::all()}; the filters are carried
     * onto every page request, so a filtered walk narrows server-side. `limit`
     * sets the page size.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return \Generator<int, Customer>
     */
    public function autoPagingIterator(array $params = [], array $opts = []): \Generator
    {
        yield from $this->all($params, $opts)->autoPagingIterator();
    }
}
