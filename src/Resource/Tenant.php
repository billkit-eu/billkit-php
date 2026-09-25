<?php

declare(strict_types=1);

namespace BillKit\Resource;

/**
 * Read + mutate tenant-level configuration: Mollie capability cache, the
 * portal-branding row, your own billing profile, the account's data
 * export, and the encrypted provider credential.
 */
final class Tenant extends BaseResource
{
    /**
     * Fetch the cached Mollie capability profile (enabled methods, etc.).
     *
     * @return array<string, mixed>
     */
    public function capabilities(): array
    {
        return $this->get('/v1/tenant/capabilities');
    }

    /**
     * Fetch the current portal-branding row.
     *
     * @return array<string, mixed>
     */
    public function portalBranding(): array
    {
        return $this->get('/v1/tenant/portal_branding');
    }

    /**
     * Partial-update the portal branding row. Only supplied fields are
     * sent; an empty string explicitly clears a field.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function setPortalBranding(array $params = []): array
    {
        return $this->post('/v1/tenant/portal_branding', $params);
    }

    /**
     * Read your own registered country and VAT number.
     *
     * These are what your customers' VAT is decided against, so check them
     * before you take your first live payment. ``country_code`` is what you
     * have stored and can be ``null``; ``effective_country_code`` is what
     * the next charge will really use. The two differ only when you have
     * stored nothing, which is exactly the case worth spotting. ``vat_id``
     * has no effective counterpart, because nothing can stand in for a
     * registration.
     *
     * @return array<string, mixed>
     */
    public function billingProfile(): array
    {
        return $this->get('/v1/tenant/billing_profile');
    }

    /**
     * Set the seller identity: jurisdiction, VAT id, invoice address.
     *
     * ``country_code`` is required on every call: there is nothing to leave
     * alone about a jurisdiction, and it decides whether a customer's sale
     * is domestic, cross-border within the EU, or outside it.
     *
     * The address fields and ``registration_number`` are partial-update,
     * and this is one of the places in the SDK where a ``null`` is a
     * value rather than an omission: leave a key out and the stored value
     * is untouched, pass it as ``null`` and it is **cleared**. Moving
     * office is a real event, so an address that could be set once and
     * never emptied would force you to keep printing something untrue.
     *
     * ``vat_id`` can be set once. After that, a different value or ``null``
     * throws {@see \BillKit\Exception\InvalidRequestException}
     * (``param`` ``vat_id``, reason ``vat_id_locked``) and the call writes
     * nothing; re-sending the stored number is accepted. BillKit invoices
     * you reverse-charged against it, so support changes it.
     *
     * Changes take effect on your next charge only. Tax is worked out
     * before money moves and written onto the payment and its invoice, so
     * correcting a country here never reprices an issued document.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function setBillingProfile(array $params): array
    {
        $body = ['country_code' => $params['country_code'] ?? null];
        foreach (
            ['vat_id', 'address_line1', 'address_line2', 'postal_code', 'city', 'registration_number'] as $field
        ) {
            // array_key_exists, not isset: an explicit null is the clear.
            if (array_key_exists($field, $params)) {
                $body[$field] = $params[$field];
            }
        }

        return $this->postFixed(
            '/v1/tenant/billing_profile',
            $body,
            $this->idempotencyKeyOf($params),
        );
    }

    /**
     * Download everything in the account as one JSON document, as raw
     * bytes.
     *
     *     file_put_contents('export.json', $client->tenant->export());
     *
     * The GDPR Article 20 portability route, and the way to take a backup:
     * catalogue, customers, subscriptions, every payment with its refunds,
     * credit notes, disputes, invoices with line items, usage records and
     * the event log. Each record has the same shape its ``GET`` route
     * returns, and ``billkit_export_version`` names the shape.
     *
     * It is ``application/json`` streamed inline, with no redirect, and it
     * can be large, so write it to a file rather than holding it in memory.
     * Test and live data export separately: you get whichever mode the
     * calling key belongs to. Nothing is changed, but the access is
     * recorded in your audit log.
     */
    public function export(): string
    {
        return $this->transport->requestBytes('GET', '/v1/tenant/export');
    }

    /**
     * Rotate the encrypted provider credential for this tenant. The new
     * ``api_key`` is encrypted server-side and never logged.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function rotateProviderCredential(array $params): array
    {
        return $this->post('/v1/tenant/provider_credential', $params);
    }
}
