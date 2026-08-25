<?php

declare(strict_types=1);

namespace BillKit\Tests;

use BillKit\Exception\WebhookVerificationException;
use BillKit\Webhooks;
use PHPUnit\Framework\TestCase;

final class WebhooksTest extends TestCase
{
    private const SECRET = 'whsec_test_secret';

    /** Build the header the server would send for a given body + timestamp. */
    private function signature(string $payload, int $timestamp, string $secret = self::SECRET): string
    {
        $sig = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);

        return "t={$timestamp},v1={$sig}";
    }

    public function testVerifiesValidSignatureAndReturnsDecodedEvent(): void
    {
        $now = 1_700_000_000;
        $payload = '{"id":"evt_1","type":"customer.created"}';
        $header = $this->signature($payload, $now);

        $event = Webhooks::verifySignature($payload, $header, self::SECRET, now: $now);

        self::assertSame('evt_1', $event['id']);
        self::assertSame('customer.created', $event['type']);
    }

    public function testRejectsExpiredTimestamp(): void
    {
        $now = 1_700_000_000;
        $payload = '{"id":"evt_1"}';
        $header = $this->signature($payload, $now - 3600);

        $this->expectException(WebhookVerificationException::class);
        Webhooks::verifySignature($payload, $header, self::SECRET, now: $now);
    }

    public function testRejectsTamperedPayload(): void
    {
        $now = 1_700_000_000;
        $header = $this->signature('{"id":"evt_1"}', $now);

        $this->expectException(WebhookVerificationException::class);
        Webhooks::verifySignature('{"id":"evt_TAMPERED"}', $header, self::SECRET, now: $now);
    }

    public function testRejectsWrongSecret(): void
    {
        $now = 1_700_000_000;
        $payload = '{"id":"evt_1"}';
        $header = $this->signature($payload, $now, 'whsec_other');

        $this->expectException(WebhookVerificationException::class);
        Webhooks::verifySignature($payload, $header, self::SECRET, now: $now);
    }

    public function testRejectsMissingHeader(): void
    {
        $this->expectException(WebhookVerificationException::class);
        Webhooks::verifySignature('{"id":"evt_1"}', null, self::SECRET);
    }

    public function testRejectsMalformedHeader(): void
    {
        $this->expectException(WebhookVerificationException::class);
        Webhooks::verifySignature('{"id":"evt_1"}', 'not-a-valid-header', self::SECRET, now: 1_700_000_000);
    }

    public function testRejectsMalformedV1Hex(): void
    {
        $now = 1_700_000_000;
        $this->expectException(WebhookVerificationException::class);
        Webhooks::verifySignature('{"id":"evt_1"}', "t={$now},v1=zzzz", self::SECRET, now: $now);
    }

    public function testRejectsNonJsonBodyWithValidSignature(): void
    {
        $now = 1_700_000_000;
        $payload = 'not json at all';
        $header = $this->signature($payload, $now);

        $this->expectException(WebhookVerificationException::class);
        Webhooks::verifySignature($payload, $header, self::SECRET, now: $now);
    }

    public function testAcceptsWhenAnyOfMultipleV1Matches(): void
    {
        // Rotation shape: an old (wrong) signature alongside the current one.
        $now = 1_700_000_000;
        $payload = '{"id":"evt_1"}';
        $good = hash_hmac('sha256', $now . '.' . $payload, self::SECRET);
        $bad = hash_hmac('sha256', $now . '.' . $payload, 'whsec_old_rotated_out');
        $header = "t={$now},v1={$bad},v1={$good}";

        $event = Webhooks::verifySignature($payload, $header, self::SECRET, now: $now);

        self::assertSame('evt_1', $event['id']);
    }

    public function testRejectsWhenNoneOfMultipleV1Match(): void
    {
        $now = 1_700_000_000;
        $payload = '{"id":"evt_1"}';
        $bad1 = hash_hmac('sha256', $now . '.' . $payload, 'whsec_wrong_a');
        $bad2 = hash_hmac('sha256', $now . '.' . $payload, 'whsec_wrong_b');
        $header = "t={$now},v1={$bad1},v1={$bad2}";

        $this->expectException(WebhookVerificationException::class);
        Webhooks::verifySignature($payload, $header, self::SECRET, now: $now);
    }

    public function testAcceptsSignatureAtToleranceEdge(): void
    {
        $now = 1_700_000_000;
        $payload = '{"id":"evt_1"}';
        // Exactly at the boundary (<= tolerance) must still verify.
        $header = $this->signature($payload, $now - 300);

        $event = Webhooks::verifySignature($payload, $header, self::SECRET, toleranceSeconds: 300, now: $now);

        self::assertSame('evt_1', $event['id']);
    }
}
