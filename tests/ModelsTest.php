<?php

declare(strict_types=1);

namespace BillKit\Tests;

use BillKit\BillKitObject;
use BillKit\Model\Coupon;
use BillKit\Model\ExpandedCustomer;
use BillKit\Model\Price;
use BillKit\Model\PriceTier;
use BillKit\Model\Subscription;
use BillKit\Tests\Support\MockHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * The typed response objects: both access styles, immutability, and
 * forward compatibility with fields and enum values this SDK predates.
 */
final class ModelsTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function subscription(): array
    {
        return [
            'id' => 'sub_1',
            'object' => 'subscription',
            'created' => 1_700_000_000,
            'livemode' => false,
            'customer_id' => 'cus_1',
            'price_id' => 'price_1',
            'status' => 'active',
            'renewal_state' => 'auto_renew',
            'serves_customer' => true,
            'cancel_at_period_end' => false,
            'canceled_at' => null,
            'current_period_end' => 1_702_592_000,
            'customer' => ['id' => 'cus_1', 'object' => 'customer.summary', 'name' => null, 'email' => 'ada@example.com', 'country_code' => 'NL'],
            'discount' => null,
        ];
    }

    public function testKnownFieldsAreTypedPropertiesAndArrayKeys(): void
    {
        $sub = Subscription::constructFrom(self::subscription());

        self::assertSame('sub_1', $sub->id);
        self::assertSame('sub_1', $sub['id']);
        self::assertTrue($sub->serves_customer);
        self::assertNull($sub->canceled_at);
        self::assertSame(Subscription::STATUS_ACTIVE, $sub->status);
        self::assertSame(Subscription::RENEWAL_STATE_AUTO_RENEW, $sub->renewal_state);
    }

    public function testNestedObjectsAreTypedToo(): void
    {
        $sub = Subscription::constructFrom(self::subscription());

        self::assertInstanceOf(ExpandedCustomer::class, $sub->customer);
        self::assertSame('ada@example.com', $sub->customer->email);
        self::assertSame('ada@example.com', $sub['customer']['email']);
        self::assertNull($sub->discount);
    }

    public function testListsOfObjectsAreTyped(): void
    {
        $price = Price::constructFrom([
            'id' => 'price_1',
            'tiers' => [
                ['up_to' => 1000, 'unit_amount_decimal' => '1', 'flat_amount_cents' => null],
                ['up_to' => null, 'unit_amount_decimal' => '0.5', 'flat_amount_cents' => null],
            ],
        ]);

        self::assertNotNull($price->tiers);
        self::assertContainsOnlyInstancesOf(PriceTier::class, $price->tiers);
        self::assertSame('0.5', $price->tiers[1]->unit_amount_decimal);
        self::assertNull($price->tiers[1]->up_to);
    }

    public function testAnUnknownFieldIsKeptAndReachableBothWays(): void
    {
        $sub = Subscription::constructFrom(self::subscription() + [
            'field_added_in_2027' => ['nested' => 1],
        ]);

        self::assertSame(['nested' => 1], $sub['field_added_in_2027']);
        self::assertSame(['nested' => 1], $sub->field_added_in_2027);
        self::assertTrue(isset($sub['field_added_in_2027']));
        self::assertTrue($sub->has('field_added_in_2027'));
        self::assertSame(['nested' => 1], $sub->toArray()['field_added_in_2027']);
    }

    public function testAnUnknownEnumValueIsJustAString(): void
    {
        $sub = Subscription::constructFrom(['status' => 'status_from_the_future'] + self::subscription());

        self::assertSame('status_from_the_future', $sub->status);
    }

    public function testAnAbsentKeyReadsAsNullAndHasDistinguishesNull(): void
    {
        $sub = Subscription::constructFrom(self::subscription());

        self::assertNull($sub['no_such_key']);
        self::assertNull($sub->no_such_key);
        self::assertFalse(isset($sub['canceled_at']));
        self::assertTrue($sub->has('canceled_at'));
        self::assertFalse($sub->has('no_such_key'));
    }

    public function testToArrayAndJsonAreTheRawResponse(): void
    {
        $raw = self::subscription();
        $sub = Subscription::constructFrom($raw);

        self::assertSame($raw, $sub->toArray());
        self::assertSame(json_encode($raw), json_encode($sub));
        self::assertSame(array_keys($raw), $sub->keys());
        self::assertCount(count($raw), $sub);
        self::assertSame($raw['id'], iterator_to_array($sub)['id']);
    }

    public function testObjectsAreReadOnly(): void
    {
        $sub = Subscription::constructFrom(self::subscription());

        foreach ([
            static function () use ($sub): void {
                $sub['status'] = 'canceled';
            },
            static function () use ($sub): void {
                unset($sub['status']);
            },
            static function () use ($sub): void {
                $sub->not_declared = 1;
            },
        ] as $write) {
            try {
                $write();
                self::fail('a BillKit object accepted a write');
            } catch (\LogicException) {
            }
        }

        // A declared property is a PHP readonly property: assigning one is
        // PHP's own \Error, which is what the BillKitObject docblock says.
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Cannot modify readonly property');
        $sub->status = 'canceled';
    }

    public function testAMissingRequiredFieldReadsAsNullAndNeverThrows(): void
    {
        // Neither decoding nor reading may throw when a payload lacks a
        // field the schema requires; the raw shape stays readable.
        $coupon = Coupon::constructFrom(['id' => 'co_1', 'percent_off' => 'not-a-number']);

        self::assertSame('co_1', $coupon->id);
        self::assertNull($coupon->percent_off);
        self::assertSame('not-a-number', $coupon['percent_off']);
        // `code` is required and absent: null, not uninitialised.
        self::assertNull($coupon->code);
        self::assertFalse($coupon->has('code'));
    }

    public function testEveryDeclaredPropertyOfEveryModelIsNullableAndReadableOnAnEmptyPayload(): void
    {
        // An event snapshot is served as recorded, so a model can be built
        // from a payload that predates any of its fields. Every property must
        // then read as null rather than throw "must not be accessed before
        // initialization".
        foreach (glob(__DIR__ . '/../src/Model/*.php') ?: [] as $path) {
            $class = 'BillKit\\Model\\' . basename($path, '.php');
            $reflection = new \ReflectionClass($class);
            if (!$reflection->isSubclassOf(BillKitObject::class)) {
                continue;
            }
            /** @var BillKitObject $object */
            $object = $class::constructFrom([]);
            foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
                if ($property->isStatic()) {
                    continue;
                }
                $type = $property->getType();
                self::assertTrue($type === null || $type->allowsNull(), "{$class}::\${$property->getName()} must be nullable");
                self::assertNull($property->getValue($object), "{$class}::\${$property->getName()} on an empty payload");
            }
        }
    }

    public function testAnOldEventSnapshotWithoutAFieldIsStillReadable(): void
    {
        // `subscription.updated` recorded before `serves_customer` and
        // `renewal_state` existed, and with no `object` either.
        $event = \BillKit\Model\Event::constructFrom([
            'id' => 'evt_1',
            'object' => 'event',
            'type' => 'subscription.updated',
            'created' => 1_700_000_000,
            'livemode' => false,
            'data' => ['id' => 'sub_1', 'status' => 'active'],
            'previous_attributes' => null,
        ]);

        self::assertInstanceOf(\BillKit\Model\SubscriptionUpdatedEvent::class, $event);
        self::assertInstanceOf(Subscription::class, $event->data);
        self::assertSame('active', $event->data->status);
        self::assertNull($event->data->serves_customer);
        self::assertNull($event->data->renewal_state);
        self::assertNull($event->data->object);
    }

    public function testJsonEncodeKeepsAnEmptyObjectFieldAnObject(): void
    {
        $customer = \BillKit\Model\Customer::constructFrom(['id' => 'cus_1', 'metadata' => [], 'unknown_list' => []]);

        self::assertSame('{"id":"cus_1","metadata":{},"unknown_list":[]}', json_encode($customer));
        // Nested, and inside a page.
        $event = \BillKit\Model\Event::constructFrom([
            'type' => 'customer.updated',
            'data' => ['id' => 'cus_1', 'metadata' => []],
            'previous_attributes' => [],
        ]);
        self::assertSame('{"type":"customer.updated","data":{"id":"cus_1","metadata":{}},"previous_attributes":{}}', json_encode($event));
        $page = \BillKit\Collection::constructFrom(['object' => 'list', 'data' => [['metadata' => []]], 'has_more' => false], \BillKit\Model\Customer::class);
        self::assertSame('{"object":"list","data":[{"metadata":{}}],"has_more":false}', json_encode($page));
        // toArray() has one array type, and says so.
        self::assertSame([], $customer->toArray()['metadata']);
        self::assertSame('{}', json_encode(BillKitObject::constructFrom([])));
    }

    public function testAWholeFloatIsAcceptedForAnInt(): void
    {
        $coupon = Coupon::constructFrom(['amount_off_cents' => 500.0]);

        self::assertSame(500, $coupon->amount_off_cents);
    }

    public function testPercentOffIsAFloatWithUpToTwoDecimals(): void
    {
        // 1.0 allows a fractional percentage; a whole one sent as an int
        // still reads as a float, so a caller never has to handle both.
        self::assertSame(12.5, Coupon::constructFrom(['percent_off' => 12.5])->percent_off);
        self::assertSame(33.33, Coupon::constructFrom(['percent_off' => 33.33])->percent_off);
        self::assertSame(10.0, Coupon::constructFrom(['percent_off' => 10])->percent_off);

        // The API writes a whole percent as an integer (99) and a fractional
        // one as a number (12.5). Both print the way the API wrote them.
        $whole = Coupon::constructFrom(json_decode('{"percent_off": 99}', true, flags: JSON_THROW_ON_ERROR));
        self::assertSame(99.0, $whole->percent_off);
        self::assertSame('99% off', $whole->percent_off . '% off');
        self::assertSame(99, $whole['percent_off']);
        $fraction = Coupon::constructFrom(json_decode('{"percent_off": 12.5}', true, flags: JSON_THROW_ON_ERROR));
        self::assertSame('12.5% off', $fraction->percent_off . '% off');
    }

    public function testTheGenericObjectHasNoDeclaredFields(): void
    {
        $obj = BillKitObject::constructFrom(['id' => 'x_1', 'object' => 'thing']);

        self::assertSame('x_1', $obj->id);
        self::assertSame('x_1', $obj['id']);
    }

    public function testResourceMethodsReturnTheTypedClass(): void
    {
        $http = (new MockHttpClient())->stage(200, self::subscription());
        $psr17 = new \Nyholm\Psr7\Factory\Psr17Factory();
        $client = new \BillKit\BillKitClient(
            apiKey: 'bk_test_unit',
            httpClient: $http,
            requestFactory: $psr17,
            streamFactory: $psr17,
        );

        $sub = $client->subscriptions->retrieve('sub_1');

        self::assertInstanceOf(Subscription::class, $sub);
    }

    public function testCouponCarriesPercentOffAndAmountOffCents(): void
    {
        $percent = Coupon::constructFrom(['discount_type' => 'percent', 'percent_off' => 25, 'amount_off_cents' => null]);
        $fixed = Coupon::constructFrom(['discount_type' => 'fixed_amount', 'percent_off' => null, 'amount_off_cents' => 500]);

        self::assertSame(Coupon::DISCOUNT_TYPE_PERCENT, $percent->discount_type);
        self::assertSame(25.0, $percent->percent_off);
        self::assertSame(Coupon::DISCOUNT_TYPE_FIXED_AMOUNT, $fixed->discount_type);
        self::assertSame(500, $fixed->amount_off_cents);
    }
}
