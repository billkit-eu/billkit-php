<?php

declare(strict_types=1);

namespace BillKit;

use BillKit\Exception\WebhookVerificationException;

/**
 * Verify ``BillKit-Signature: t=<unix>,v1=<hex>`` webhook headers.
 *
 * The scheme matches the server's ``sign_outbound`` / ``verify_outbound``:
 *
 *   1. Parse the header (reject malformed shapes). A header may carry more
 *      than one ``v1=`` value, because the server emits both the old and new
 *      signature during a signing-secret rotation, and verification passes
 *      if **any** of them matches.
 *   2. Confirm the timestamp is within ``$toleranceSeconds`` of now
 *      (replay protection).
 *   3. Compute ``HMAC-SHA256("{t}." + body, secret)`` and compare against
 *      each supplied ``v1`` in constant time.
 */
final class Webhooks
{
    public const DEFAULT_TOLERANCE_SECONDS = 300;

    private const V1_HEX_LENGTH = 64;

    /**
     * @param int|null $now Injectable clock (unix seconds) for tests.
     *
     * @return array<string, mixed> The decoded, verified event payload.
     *
     * @throws WebhookVerificationException on any verification failure.
     */
    public static function verifySignature(
        string $payload,
        ?string $signatureHeader,
        string $secret,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
        ?int $now = null,
    ): array {
        if ($signatureHeader === null || $signatureHeader === '') {
            throw new WebhookVerificationException('Missing BillKit-Signature header.');
        }

        [$timestamp, $signatures] = self::parseHeader($signatureHeader);

        $nowTs = $now ?? time();
        if (abs($nowTs - $timestamp) > $toleranceSeconds) {
            throw new WebhookVerificationException(
                "Signature timestamp outside +/-{$toleranceSeconds}s tolerance.",
            );
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        $matched = false;
        foreach ($signatures as $candidate) {
            // Compare every well-formed candidate; do not break on the first
            // match so the loop's timing does not reveal which one matched.
            if (
                strlen($candidate) === self::V1_HEX_LENGTH
                && ctype_xdigit($candidate)
                && hash_equals($expected, strtolower($candidate))
            ) {
                $matched = true;
            }
        }
        if (!$matched) {
            throw new WebhookVerificationException('Signature mismatch.');
        }

        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $err) {
            throw new WebhookVerificationException('Webhook body is not valid JSON: ' . $err->getMessage());
        }
        if (!is_array($decoded)) {
            throw new WebhookVerificationException('Webhook body is not a JSON object.');
        }

        $event = [];
        foreach ($decoded as $key => $value) {
            $event[(string) $key] = $value;
        }

        return $event;
    }

    /**
     * @return array{0: int, 1: non-empty-list<string>} timestamp, one-or-more v1 hex values
     */
    private static function parseHeader(string $header): array
    {
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $chunk) {
            $idx = strpos($chunk, '=');
            if ($idx === false) {
                continue;
            }
            $key = trim(substr($chunk, 0, $idx));
            $value = trim(substr($chunk, $idx + 1));
            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $timestamp === '' || $signatures === []) {
            throw new WebhookVerificationException("Malformed BillKit-Signature header: {$header}");
        }
        if (!ctype_digit($timestamp)) {
            throw new WebhookVerificationException("Malformed timestamp in BillKit-Signature: {$timestamp}");
        }

        return [(int) $timestamp, $signatures];
    }
}
