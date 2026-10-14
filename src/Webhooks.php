<?php

declare(strict_types=1);

namespace BillKit;

use BillKit\Exception\WebhookVerificationException;
use BillKit\Model\Event;

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
 *
 * The verified body is decoded into a typed event: `SubscriptionUpdatedEvent`
 * for `subscription.updated`, and so on for every type this SDK version
 * knows, or a plain {@see Event} for one it does not. A new event type is
 * never an error; ignore what you do not handle.
 */
final class Webhooks
{
    public const DEFAULT_TOLERANCE_SECONDS = 300;

    private const V1_HEX_LENGTH = 64;

    /**
     * The most digits a real ``t=`` carries. Ten digits of unix seconds last
     * until the year 2286; anything longer is not a timestamp the server
     * wrote, and past 19 digits PHP's ``(int)`` saturates at PHP_INT_MAX
     * rather than failing.
     */
    private const MAX_TIMESTAMP_DIGITS = 10;

    /**
     * @param string   $secret the endpoint's signing secret (`bkwhsec_...`); never empty
     * @param int|null $now    Injectable clock (unix seconds) for tests.
     *
     * @return Event the verified event, as its typed subclass when the type is known
     *
     * @throws \InvalidArgumentException  when `$secret` is empty or whitespace, or `$toleranceSeconds` is negative:
     *                                     a configuration fault, not a bad request
     * @throws WebhookVerificationException on any verification failure.
     */
    public static function verifySignature(
        string $payload,
        ?string $signatureHeader,
        string $secret,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
        ?int $now = null,
    ): Event {
        // An empty secret is a deployment that never loaded its secret (an
        // unset env var). HMAC with an empty key is still a valid HMAC, so
        // without this anyone who reads the scheme could sign a payload the
        // endpoint accepts. It throws a different class from a bad request
        // on purpose: this is the app's fault, and it must not be swallowed
        // by the handler that answers forged webhooks with a 400.
        if (trim($secret) === '') {
            throw new \InvalidArgumentException(
                'BillKit: the webhook signing secret is empty. Pass the endpoint\'s bkwhsec_... secret.',
            );
        }
        if ($toleranceSeconds < 0) {
            throw new \InvalidArgumentException('BillKit: the webhook tolerance is a number of seconds, 0 or more.');
        }
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
        if (!is_array($decoded) || !str_starts_with(ltrim($payload), '{')) {
            throw new WebhookVerificationException('Webhook body is not a JSON object.');
        }

        return Event::constructFrom($decoded);
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
        // ``ctype_digit`` already refuses a sign, so a negative ``t`` never
        // gets this far; ``0`` would, and it is refused here rather than
        // several lines later by the tolerance window, so the message names
        // the real fault and matches node's wording. A run of digits longer
        // than any real timestamp is refused for the same reason.
        if (
            !ctype_digit($timestamp)
            || strlen($timestamp) > self::MAX_TIMESTAMP_DIGITS
            || (int) $timestamp <= 0
        ) {
            throw new WebhookVerificationException("Malformed timestamp in BillKit-Signature: {$timestamp}");
        }

        return [(int) $timestamp, $signatures];
    }
}
