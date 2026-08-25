<?php

declare(strict_types=1);

namespace BillKit\Resource;

/**
 * Read + mutate tenant-level configuration: Mollie capability cache,
 * portal-branding row, and the encrypted provider credential.
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
