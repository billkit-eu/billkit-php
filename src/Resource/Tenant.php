<?php

declare(strict_types=1);

namespace BillKit\Resource;

use BillKit\Model\TenantBillingProfile;
use BillKit\Model\TenantCapabilities;
use BillKit\Model\TenantPortalBranding;
use BillKit\Model\TenantProviderCredential;

/**
 * Your own account: capabilities, branding, billing profile, export.
 *
 * @internal constructed by {@see \BillKit\BillKitClient}; use `$client->tenant`.
 */
final class Tenant extends BaseResource
{
    /**
     * The payment methods and capabilities the account can use, as last read
     * from the provider.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return TenantCapabilities
     */
    public function retrieveCapabilities(array $params = [], array $opts = []): TenantCapabilities
    {
        return $this->getObject('/v1/tenant/capabilities', $params, $opts, TenantCapabilities::class);
    }

    /**
     * The customer portal branding.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return TenantPortalBranding
     */
    public function retrievePortalBranding(array $params = [], array $opts = []): TenantPortalBranding
    {
        return $this->getObject('/v1/tenant/portal_branding', $params, $opts, TenantPortalBranding::class);
    }

    /**
     * Change the customer portal branding. Only the fields you send change:
     * leave a key out to keep it, pass `null` to clear it. All five
     * (`business_name`, `support_email`, `logo_url`, `theme`, `capabilities`)
     * can be cleared. An empty string is not a clear: it is a 400.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return TenantPortalBranding
     */
    public function setPortalBranding(array $params = [], array $opts = []): TenantPortalBranding
    {
        return $this->postObject(
            '/v1/tenant/portal_branding',
            $params,
            $opts,
            TenantPortalBranding::class,
            ['business_name', 'support_email', 'logo_url', 'theme', 'capabilities'],
        );
    }

    /**
     * Your registered country and VAT number, which your customers' VAT is
     * decided against.
     *
     * `country_code` is what you stored and can be `null`;
     * `effective_country_code` is what the next charge will really use. Check
     * both before your first live payment.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return TenantBillingProfile
     */
    public function retrieveBillingProfile(array $params = [], array $opts = []): TenantBillingProfile
    {
        return $this->getObject('/v1/tenant/billing_profile', $params, $opts, TenantBillingProfile::class);
    }

    /**
     * Set the seller identity: jurisdiction, VAT id, invoice address.
     *
     * `country_code` is required on every call. The address fields and
     * `registration_number` are partial-update and take `null` as a value: leave
     * a key out to keep it, pass `null` to clear it. `vat_id` can be set once;
     * after that a different value or `null` is a 400 `vat_id_locked` and
     * nothing is written. Changes apply from your next charge; issued documents
     * are never repriced.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return TenantBillingProfile
     */
    public function setBillingProfile(array $params = [], array $opts = []): TenantBillingProfile
    {
        // Built like every other update: what you pass is sent (so an
        // unknown key reaches the API and is refused there, not dropped
        // here), nulls are stripped except on the keys whose null is a
        // clear.
        return $this->postObject(
            '/v1/tenant/billing_profile',
            $params,
            $opts,
            TenantBillingProfile::class,
            ['vat_id', 'address_line1', 'address_line2', 'postal_code', 'city', 'registration_number'],
        );
    }

    /**
     * Everything in the account as one JSON document, as raw bytes: the GDPR
     * Article 20 portability export, and a backup.
     *
     *     file_put_contents('export.json', $client->tenant->export());
     *
     * It can be large, so write it to a file. You get the mode of the calling
     * key. The access is recorded in your audit log.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return string
     */
    public function export(array $params = [], array $opts = []): string
    {
        return $this->getBytes('/v1/tenant/export', $opts);
    }

    /**
     * Replace the provider credential. The new key is encrypted server-side and
     * never logged.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return TenantProviderCredential
     */
    public function rotateProviderCredential(array $params = [], array $opts = []): TenantProviderCredential
    {
        return $this->postObject('/v1/tenant/provider_credential', $params, $opts, TenantProviderCredential::class);
    }
}
