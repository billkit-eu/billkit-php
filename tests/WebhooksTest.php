<?php

declare(strict_types=1);

namespace BillKit\Tests;

use BillKit\Exception\BillKitException;
use BillKit\Exception\WebhookVerificationException;
use BillKit\Model\DunningEmailRequiredEvent;
use BillKit\Model\DunningNotification;
use BillKit\Model\Event;
use BillKit\Model\Subscription;
use BillKit\Model\SubscriptionUpdatedEvent;
use BillKit\Webhooks;
use PHPUnit\Framework\TestCase;

final class WebhooksTest extends TestCase
{
    private const SECRET = 'bkwhsec_test_secret';

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
        $header = $this->signature($payload, $now, 'bkwhsec_other');

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
        $bad = hash_hmac('sha256', $now . '.' . $payload, 'bkwhsec_old_rotated_out');
        $header = "t={$now},v1={$bad},v1={$good}";

        $event = Webhooks::verifySignature($payload, $header, self::SECRET, now: $now);

        self::assertSame('evt_1', $event['id']);
    }

    public function testRejectsWhenNoneOfMultipleV1Match(): void
    {
        $now = 1_700_000_000;
        $payload = '{"id":"evt_1"}';
        $bad1 = hash_hmac('sha256', $now . '.' . $payload, 'bkwhsec_wrong_a');
        $bad2 = hash_hmac('sha256', $now . '.' . $payload, 'bkwhsec_wrong_b');
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

    public function testZeroTimestampIsMalformedNotMerelyStale(): void
    {
        // `t=0` would also fail the tolerance window, but naming it as
        // malformed is the honest answer and is what node says too.
        $this->expectException(WebhookVerificationException::class);
        $this->expectExceptionMessage('Malformed timestamp');
        Webhooks::verifySignature(
            payload: '{}',
            signatureHeader: 't=0,v1=' . str_repeat('a', 64),
            secret: 'bkwhsec_x',
        );
    }

    public function testAnEmptySecretIsRefusedEvenForAPayloadSignedWithIt(): void
    {
        // HMAC with an empty key is a valid HMAC, so a deployment whose
        // secret env var never loaded would otherwise accept a forgery
        // signed by anyone.
        $now = 1_700_000_000;
        $payload = '{"id":"evt_forged"}';
        $header = $this->signature($payload, $now, '');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('signing secret is empty');
        Webhooks::verifySignature($payload, $header, '', now: $now);
    }

    public function testAWhitespaceSecretIsRefusedEvenForAPayloadSignedWithIt(): void
    {
        $now = 1_700_000_000;
        $payload = '{"id":"evt_forged"}';
        $header = $this->signature($payload, $now, " \t\n");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('signing secret is empty');
        Webhooks::verifySignature($payload, $header, " \t\n", now: $now);
    }

    public function testANegativeToleranceIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Webhooks::verifySignature('{}', 't=1700000000,v1=' . str_repeat('a', 64), self::SECRET, toleranceSeconds: -1);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function absurdTimestamps(): iterable
    {
        yield 'eleven digits' => ['17000000000'];
        yield 'saturates PHP_INT_MAX' => ['99999999999999999999999'];
        yield 'leading zeros' => ['0001700000000'];
        yield 'decimal' => ['1700000000.5'];
        yield 'hex' => ['0x6553f100'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('absurdTimestamps')]
    public function testAnAbsurdTimestampIsMalformed(string $t): void
    {
        $this->expectException(WebhookVerificationException::class);
        $this->expectExceptionMessage('Malformed timestamp');
        // A tolerance wide enough that only the shape check can refuse it.
        Webhooks::verifySignature('{}', "t={$t},v1=" . str_repeat('a', 64), self::SECRET, toleranceSeconds: PHP_INT_MAX, now: 1_700_000_000);
    }

    public function testAKnownTypeDecodesIntoItsTypedEventWithTypedData(): void
    {
        $now = 1_700_000_000;
        $payload = (string) json_encode([
            'id' => 'evt_1',
            'object' => 'event',
            'type' => 'subscription.updated',
            'created' => $now,
            'livemode' => false,
            'data' => ['id' => 'sub_1', 'object' => 'subscription', 'status' => 'past_due', 'renewal_state' => 'auto_renew'],
            'previous_attributes' => ['status' => 'active'],
        ]);

        $event = Webhooks::verifySignature($payload, $this->signature($payload, $now), self::SECRET, now: $now);

        self::assertInstanceOf(SubscriptionUpdatedEvent::class, $event);
        self::assertSame(SubscriptionUpdatedEvent::TYPE, $event->type);
        self::assertInstanceOf(Subscription::class, $event->data);
        self::assertSame('past_due', $event->data->status);
        self::assertSame(['status' => 'active'], $event->previous_attributes);
        // The 0.x array reads still work.
        self::assertSame('past_due', $event['data']['status']);
    }

    public function testPreviousAttributesIsNullableAndPresent(): void
    {
        $now = 1_700_000_000;
        $payload = (string) json_encode([
            'id' => 'evt_2', 'object' => 'event', 'type' => 'subscription.updated', 'created' => $now,
            'livemode' => false, 'data' => ['id' => 'sub_1', 'object' => 'subscription'], 'previous_attributes' => null,
        ]);

        $event = Webhooks::verifySignature($payload, $this->signature($payload, $now), self::SECRET, now: $now);

        self::assertNull($event->previous_attributes);
        self::assertTrue($event->has('previous_attributes'));
    }

    public function testANonResourceDataObjectIsTypedToo(): void
    {
        $now = 1_700_000_000;
        $payload = (string) json_encode([
            'id' => 'evt_3', 'object' => 'event', 'type' => 'dunning.email_required', 'created' => $now,
            'livemode' => true, 'previous_attributes' => null,
            'data' => ['object' => 'dunning_notification', 'subscription_id' => 'sub_1', 'attempt' => 2, 'amount_cents' => 999],
        ]);

        $event = Webhooks::verifySignature($payload, $this->signature($payload, $now), self::SECRET, now: $now);

        self::assertInstanceOf(DunningEmailRequiredEvent::class, $event);
        self::assertInstanceOf(DunningNotification::class, $event->data);
        self::assertSame(2, $event->data->attempt);
    }

    public function testAnUnknownTypeWithUnknownFieldsNeverThrows(): void
    {
        $now = 1_700_000_000;
        $payload = (string) json_encode([
            'id' => 'evt_4', 'object' => 'event', 'type' => 'ledger.entry_posted', 'created' => $now,
            'livemode' => false, 'previous_attributes' => null, 'api_version' => '2027-01-01',
            'data' => ['id' => 'le_1', 'object' => 'ledger_entry', 'amount_cents' => 5],
        ]);

        $event = Webhooks::verifySignature($payload, $this->signature($payload, $now), self::SECRET, now: $now);

        self::assertSame(Event::class, $event::class);
        self::assertSame('ledger.entry_posted', $event->type);
        self::assertSame('2027-01-01', $event['api_version']);
        self::assertSame(5, $event->data['amount_cents']);
    }

    public function testAKnownTypeWhoseDataNamesAnUnknownObjectStillDecodes(): void
    {
        $now = 1_700_000_000;
        $payload = (string) json_encode([
            'id' => 'evt_5', 'object' => 'event', 'type' => 'customer.created', 'created' => $now,
            'livemode' => false, 'previous_attributes' => null, 'data' => ['id' => 'cus_1', 'object' => 'customer'],
        ]);

        $event = Webhooks::verifySignature($payload, $this->signature($payload, $now), self::SECRET, now: $now);

        self::assertSame('cus_1', $event->data->id);
    }

    public function testAJsonArrayBodyIsRefused(): void
    {
        $now = 1_700_000_000;
        $payload = '[1,2]';

        $this->expectException(WebhookVerificationException::class);
        Webhooks::verifySignature($payload, $this->signature($payload, $now), self::SECRET, now: $now);
    }

    public function testVerificationFailureIsABillKitException(): void
    {
        $this->expectException(BillKitException::class);
        Webhooks::verifySignature('{"id":"evt_1"}', null, self::SECRET);
    }
}
