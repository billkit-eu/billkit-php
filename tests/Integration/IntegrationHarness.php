<?php

declare(strict_types=1);

namespace BillKit\Tests\Integration;

/**
 * Live-API harness for the php SDK integration suite.
 *
 * Talks to a real BillKit API booted with `BILLKIT_E2E_TEST_LOGIN=1`
 * (see `sdk/integration/boot-api.sh`). The suite skips entirely unless
 * `BILLKIT_INTEGRATION_BASE_URL` is set, so a laptop without a running
 * stack keeps `make all-tests` green.
 *
 * Two env-gated API surfaces do the heavy lifting:
 *
 *  - `POST /v1/console/auth/_test/login` provisions a *fresh tenant* per
 *    unseen email and returns a wildcard `api_key` plus the tenant's
 *    `mollie_route_id`. Every run uses a unique email, so a suite never
 *    inherits another run's rows and list assertions stay meaningful.
 *  - `POST /v1/console/auth/_test/mollie/*` drives the in-process fake
 *    Mollie provider, which is what makes the money-path scenarios
 *    deterministic without touching real Mollie.
 *
 * Deliberately uses raw curl rather than the SDK's own transport: the
 * harness must be able to set up state even when the thing under test is
 * broken, and these endpoints are not part of the SDK surface anyway.
 */
final class IntegrationHarness
{
    public static function baseUrl(): string
    {
        $value = getenv('BILLKIT_INTEGRATION_BASE_URL');

        return is_string($value) ? $value : '';
    }

    public static function enabled(): bool
    {
        return self::baseUrl() !== '';
    }

    /**
     * Provision a brand-new tenant.
     *
     * The email is randomised per call precisely so each suite gets its own
     * tenant. List assertions ("exactly the 7 products I created") are only
     * stable under that isolation.
     *
     * @return array{api_key: string, tenant_id: string, mollie_route_id: string, session_token: string}
     */
    public static function provisionTenant(string $label = 'php-sdk-it'): array
    {
        $email = sprintf('%s-%s@sdk-it.example.com', $label, bin2hex(random_bytes(12)));
        [$status, $body] = self::postJson('/v1/console/auth/_test/login', [
            'email' => $email,
            'mode' => 'test',
            'tenant_name' => "Php SDK IT {$label}",
        ]);
        if ($status === 404) {
            throw new \RuntimeException(
                'test-login backdoor returned 404; boot the API with '
                . 'BILLKIT_E2E_TEST_LOGIN=1 (see sdk/integration/boot-api.sh).',
            );
        }
        if ($status !== 200) {
            throw new \RuntimeException("test-login failed: {$status} {$body}");
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        /** @var array{tenant_id: string} $operator */
        $operator = $decoded['operator'];

        return [
            'api_key' => (string) $decoded['api_key'],
            'tenant_id' => $operator['tenant_id'],
            'mollie_route_id' => (string) $decoded['mollie_route_id'],
            'session_token' => (string) $decoded['session_token'],
        ];
    }

    /**
     * Recover the provider payment id from a checkout session's Mollie URL.
     *
     * The API never returns `tr_...` directly, but the fake encodes it in the
     * redirect URL, which keeps this per-checkout and free of shared state.
     */
    public static function paymentIdFromCheckoutUrl(string $url): string
    {
        $parts = explode('/', $url);
        $candidate = (string) end($parts);
        if (! str_starts_with($candidate, 'tr_')) {
            throw new \RuntimeException("Expected a Mollie payment id in checkout URL, got: {$url}");
        }

        return $candidate;
    }

    /** Flip a fake payment to a terminal status. */
    public static function settle(string $paymentId, string $status = 'paid'): void
    {
        self::expectOk('/v1/console/auth/_test/mollie/settle', [
            'payment_id' => $paymentId,
            'status' => $status,
        ]);
    }

    /**
     * Settle every refund the fake holds against one payment.
     *
     * Takes the **provider** payment id (`tr_…`, what `settle()` takes), not
     * BillKit's own `pay_…`. The form a suite can actually reach: BillKit deliberately never
     * returns `provider_refund_id`, so a spec that booked a refund through
     * the real route has no handle on the Mollie row it produced. The API
     * answers 400 rather than succeeding vacuously when the payment has no
     * fake refunds, so a mis-wired call fails loudly instead of reporting a
     * settlement that never happened.
     */
    public static function settleRefundsFor(
        string $providerPaymentId,
        string $status = 'refunded',
    ): void {
        self::expectOk('/v1/console/auth/_test/mollie/refund_status', [
            'payment_id' => $providerPaymentId,
            'status' => $status,
        ]);
    }

    /** Open a chargeback on a fake payment (drives the dispute reconciler). */
    public static function chargeback(string $paymentId, string $amountValue, ?string $reason = null): void
    {
        self::expectOk('/v1/console/auth/_test/mollie/chargeback', [
            'payment_id' => $paymentId,
            'amount_value' => $amountValue,
            'reason' => $reason,
        ]);
    }

    /**
     * Post the provider webhook the way Mollie does, form-encoded `id=tr_...`.
     *
     * The API ignores the body's claims about state and re-fetches the payment
     * from the provider, so this call is only a *nudge*; `settle()` is what
     * actually decides the outcome. Driving them in that order is what makes
     * the money-path specs deterministic.
     */
    public static function deliverMollieWebhook(string $routeId, string $providerPaymentId): void
    {
        $ch = curl_init(self::baseUrl() . '/internal/webhooks/mollie/' . $routeId);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS => 'id=' . rawurlencode($providerPaymentId),
            CURLOPT_TIMEOUT => 30,
        ]);
        $body = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($status !== 200) {
            throw new \RuntimeException("mollie webhook delivery failed: {$status} {$body}");
        }
    }

    /** Mint an API key with a restricted scope set, for the scope-denial spec. */
    public static function mintScopedKey(string $apiKey, array $scopes): string
    {
        [$status, $body] = self::postJson(
            '/v1/api_keys',
            ['label' => 'scoped-' . bin2hex(random_bytes(4)), 'scopes' => $scopes],
            ['Authorization: Bearer ' . $apiKey],
        );
        if ($status !== 200) {
            throw new \RuntimeException("api key mint failed: {$status} {$body}");
        }
        /** @var array{secret: string} $decoded */
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        return $decoded['secret'];
    }

    /** A fresh idempotency key. */
    public static function idemKey(): string
    {
        return 'it-' . bin2hex(random_bytes(16));
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string>         $headers
     *
     * @return array{0: int, 1: string}
     */
    private static function postJson(string $path, array $payload, array $headers = []): array
    {
        $ch = curl_init(self::baseUrl() . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
            CURLOPT_TIMEOUT => 30,
        ]);
        $body = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return [$status, $body];
    }

    /**
     * Run `$whileInFlight` with `$count` identical POSTs genuinely in flight.
     *
     * PHP has no in-process concurrency, and one scenario needs it: `409
     * idempotency_in_progress` exists only while another request carrying the
     * same key is still running, so the thing under test has to overlap with
     * something.
     *
     * The pump loop is the part that is easy to get wrong. `curl_multi_exec`
     * is non-blocking, but libcurl only advances a transfer *while it is being
     * called* — kicking off once and walking away leaves the racers queued
     * rather than sent, and the overlap never happens. So this pumps until
     * every handle is connected and its request written, then hands control
     * back, then drains.
     *
     * @param array<string, mixed>  $payload
     * @param array<string, string> $headers
     * @param \Closure(): mixed     $whileInFlight
     *
     * @return array{0: mixed, 1: list<array{0: int, 1: string}>} the closure's
     *                                                            result, and [status, body] per racer
     */
    public static function raceJson(
        string $path,
        array $payload,
        array $headers,
        int $count,
        \Closure $whileInFlight,
    ): array {
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $lines = ['Content-Type: application/json'];
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }

        $multi = curl_multi_init();
        $handles = [];
        for ($i = 0; $i < $count; $i++) {
            $ch = curl_init(self::baseUrl() . $path);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => $lines,
                CURLOPT_POSTFIELDS => $encoded,
                CURLOPT_TIMEOUT => 30,
            ]);
            curl_multi_add_handle($multi, $ch);
            $handles[] = $ch;
        }

        // Push the connects and the request bodies out, without waiting for
        // any response. Bounded so a slow host cannot turn this into a hang:
        // the scenario degrades to "no overlap", never to a stuck suite.
        $running = 0;
        for ($pump = 0; $pump < 10; $pump++) {
            curl_multi_exec($multi, $running);
            if ($running === 0) {
                break;
            }
            if (self::allRequestsSent($handles)) {
                break;
            }
            curl_multi_select($multi, 0.02);
        }

        $result = $whileInFlight();

        do {
            curl_multi_exec($multi, $running);
            if ($running > 0) {
                curl_multi_select($multi, 0.1);
            }
        } while ($running > 0);

        $racers = [];
        foreach ($handles as $ch) {
            $racers[] = [
                (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
                (string) curl_multi_getcontent($ch),
            ];
            // No curl_close(): the handle is freed when the last reference
            // to it goes, and the function has done nothing since PHP 8.0.
            // It is deprecated in 8.5, which the CI matrix now covers.
            curl_multi_remove_handle($multi, $ch);
        }
        curl_multi_close($multi);

        return [$result, $racers];
    }

    /**
     * True once every handle has written its request body.
     *
     * `CURLINFO_SIZE_UPLOAD` is the byte counter curl keeps per transfer; it
     * only moves once the body is on the wire, which is the moment the server
     * can start holding the idempotency key.
     *
     * @param list<\CurlHandle> $handles
     */
    private static function allRequestsSent(array $handles): bool
    {
        foreach ($handles as $ch) {
            if ((float) curl_getinfo($ch, CURLINFO_SIZE_UPLOAD) <= 0.0) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $payload */
    private static function expectOk(string $path, array $payload): void
    {
        [$status, $body] = self::postJson($path, $payload);
        if ($status !== 200) {
            throw new \RuntimeException("{$path} failed: {$status} {$body}");
        }
    }
}
