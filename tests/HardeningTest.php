<?php

declare(strict_types=1);

namespace BillKit\Tests;

use BillKit\BillKitClient;
use BillKit\EventType;
use BillKit\Exception\InvalidRequestException;
use BillKit\Model\Event;
use BillKit\Model\TestClock;
use BillKit\Tests\Support\MockHttpClient;
use BillKit\Webhooks;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Transport and surface hardening from the 0.10.0 audit:
 *
 * - a blank caller ``idempotency_key`` is treated as unset, so the POST the
 *   retry loop re-sends always carries one stable, non-empty key;
 * - a JSON call never follows a redirect (the PDF path still does; see
 *   DocumentsTest);
 * - an empty id is refused locally instead of building ``/v1/customers/``;
 * - test clocks are reachable, and ``test_clock_id`` rides on customer create;
 * - the event-type constants, and that an unknown type never throws.
 */
final class HardeningTest extends BillKitTestCase
{
    private const SPEC = __DIR__ . '/../../../docs-public/public/openapi/latest.json';

    /** @return iterable<string, array{string}> */
    public static function blankKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'spaces' => ['   '];
        yield 'tab-newline' => ["\t\n"];
    }

    #[DataProvider('blankKeys')]
    public function testBlankIdempotencyKeyIsGeneratedOnceAndReused(string $blank): void
    {
        $http = (new MockHttpClient())
            ->stage(502, ['error' => ['type' => 'api_error']])
            ->stage(502, ['error' => ['type' => 'api_error']])
            ->stage(200, ['id' => 'cus_1']);
        $client = $this->makeClient($http);

        $client->customers->create(['email' => 'a@b.co'], ['idempotency_key' => $blank]);

        self::assertCount(3, $http->requests);
        $keys = array_map(
            static fn ($r): string => $r->getHeaderLine('Idempotency-Key'),
            $http->requests,
        );
        self::assertMatchesRegularExpression('/^sdk-[0-9a-f-]{36}$/', $keys[0]);
        self::assertSame([$keys[0], $keys[0], $keys[0]], $keys);
        self::assertSame(['email' => 'a@b.co'], $this->bodyArray($http->requests[0]));
    }

    public function testARealCallerKeyIsSentVerbatim(): void
    {
        $http = (new MockHttpClient())
            ->stage(502, ['error' => ['type' => 'api_error']])
            ->stage(200, []);
        $client = $this->makeClient($http);

        $client->customers->create([], ['idempotency_key' => 'order-42']);

        self::assertSame('order-42', $http->requests[0]->getHeaderLine('Idempotency-Key'));
        self::assertSame('order-42', $http->requests[1]->getHeaderLine('Idempotency-Key'));
    }

    public function testAJsonCallDoesNotFollowARedirect(): void
    {
        // Already true in php; pinned so it stays the shared contract.
        $http = (new MockHttpClient())
            ->stage(307, null, ['Location' => 'https://elsewhere.example/'])
            ->stage(200, ['id' => 'cus_from_the_redirect_target']);
        $client = $this->makeClient($http);

        try {
            $client->customers->create(['email' => 'ada@example.com']);
            self::fail('expected the 307 to surface');
        } catch (InvalidRequestException $e) {
            self::assertSame(307, $e->statusCode);
        }
        self::assertCount(1, $http->requests);
    }

    /** @return iterable<string, array{\Closure(BillKitClient): mixed}> */
    public static function emptyIdCalls(): iterable
    {
        yield 'customers.retrieve' => [static fn (BillKitClient $c) => $c->customers->retrieve('')];
        yield 'customers.update' => [static fn (BillKitClient $c) => $c->customers->update('  ', ['name' => 'x'])];
        yield 'customers.delete' => [static fn (BillKitClient $c) => $c->customers->delete('')];
        yield 'subscriptions.cancel' => [static fn (BillKitClient $c) => $c->subscriptions->cancel('')];
        yield 'invoices.retrievePdf' => [static fn (BillKitClient $c) => $c->invoices->retrievePdf('')];
        yield 'testClocks.advance' => [static fn (BillKitClient $c) => $c->testClocks->advance('', ['frozen_time' => 1])];
        yield 'webhookEndpoints.retrieveDelivery' => [
            static fn (BillKitClient $c) => $c->webhookEndpoints->retrieveDelivery('we_1', ''),
        ];
    }

    /**
     * @param \Closure(BillKitClient): mixed $call
     */
    #[DataProvider('emptyIdCalls')]
    public function testEmptyIdIsRefusedBeforeARequest(\Closure $call): void
    {
        $http = (new MockHttpClient())->stage(200, []);
        $client = $this->makeClient($http);

        try {
            $call($client);
            self::fail('expected an empty id to be refused');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('an id is required', $e->getMessage());
        }
        self::assertCount(0, $http->requests);
    }

    public function testDotSegmentIdsAreRefusedBeforeARequest(): void
    {
        // rawurlencode leaves `.` and `..` alone, and URL normalisation would
        // resolve them: `retrieve('..')` must not become `GET /v1/`.
        $http = (new MockHttpClient())->stage(200, []);
        $client = $this->makeClient($http);

        foreach (['.', '..'] as $dot) {
            try {
                $client->customers->retrieve($dot);
                self::fail("expected '{$dot}' to be refused");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('is not an id', $e->getMessage());
            }
            try {
                $client->webhookEndpoints->retrieveDelivery('we_1', $dot);
                self::fail("expected '{$dot}' to be refused");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('is not an id', $e->getMessage());
            }
        }
        self::assertCount(0, $http->requests);
    }

    public function testAQueryKeyThisVersionDoesNotKnowIsForwarded(): void
    {
        // A filter the API adds in a later 1.x must be usable from this
        // client, as it is from node and python. Refusing keys missing from
        // the document this version was generated from made it unreachable
        // until an upgrade. A key the route really does not take is the
        // API's 400 parameter_unknown, naming it.
        $list = ['object' => 'list', 'data' => [], 'has_more' => false];
        $http = (new MockHttpClient())->stage(200, $list)->stage(200, $list)->stage(200, ['id' => 'cus_1']);
        $client = $this->makeClient($http);

        $client->subscriptions->all(['customer_id' => 'cus_1', 'created_after' => 1_800_000_000]);
        self::assertSame(
            self::BASE_URL . '/v1/subscriptions?customer_id=cus_1&created_after=1800000000',
            $this->url($http->lastRequest()),
        );
        $client->subscriptions->listUsageRecords('sub_1', ['invoice_id' => 'in_1', 'source' => ['api', 'cli']]);
        self::assertSame(
            self::BASE_URL . '/v1/subscriptions/sub_1/usage_records?invoice_id=in_1&source=api%2Ccli',
            $this->url($http->lastRequest()),
        );
        $client->customers->retrieve('cus_1', ['expand' => ['stats']]);
        self::assertSame(self::BASE_URL . '/v1/customers/cus_1?expand=stats', $this->url($http->lastRequest()));

        // The value is still checked: an unknown key with no query-string
        // form is refused before any request.
        try {
            $client->subscriptions->all(['created' => ['gte' => [1]]]);
            self::fail('expected a nested array to be refused');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString("'created'", $e->getMessage());
        }
        self::assertCount(3, $http->requests);
    }

    public function testAnEmptyMapFieldIsSentAsAnObject(): void
    {
        // PHP has one array type, and json_encode([]) is `[]`: a list, which
        // the API refuses for a map with a 400. The fields the document types
        // as objects go out as `{}`; real lists keep their `[]`.
        $http = (new MockHttpClient())
            ->stage(200, ['id' => 'cus_1'])
            ->stage(200, ['id' => 'cus_1'])
            ->stage(200, ['object' => 'tenant_portal_branding'])
            ->stage(200, ['id' => 'we_1'])
            ->stage(200, ['id' => 'ur_1']);
        $client = $this->makeClient($http);

        $client->customers->create(['email' => 'a@b.co', 'metadata' => []]);
        self::assertSame('{"email":"a@b.co","metadata":{}}', (string) $http->lastRequest()->getBody());

        // A templated route resolves through its id.
        $client->customers->update('cus_1', ['metadata' => []]);
        self::assertSame('{"metadata":{}}', (string) $http->lastRequest()->getBody());

        $client->tenant->setPortalBranding(['theme' => [], 'capabilities' => [], 'business_name' => 'Acme']);
        self::assertSame(
            '{"theme":{},"capabilities":{},"business_name":"Acme"}',
            (string) $http->lastRequest()->getBody(),
        );

        // A list stays a list, and a non-empty map is untouched.
        $client->webhookEndpoints->create(['url' => 'https://x.test/h', 'enabled_events' => [], 'metadata' => ['k' => 'v']]);
        self::assertSame(
            '{"url":"https://x.test/h","enabled_events":[],"metadata":{"k":"v"}}',
            (string) $http->lastRequest()->getBody(),
        );

        $client->subscriptions->createUsageRecord('sub_1', ['quantity' => 1, 'metadata' => []]);
        self::assertSame('{"quantity":1,"metadata":{}}', (string) $http->lastRequest()->getBody());
    }

    public function testRequestMapsCoverEveryObjectFieldInTheDocument(): void
    {
        // Walked independently of the generator, so a generator that stopped
        // seeing a map (a new `$ref`, a nullable wrapper) fails here too.
        if (!is_file(self::SPEC)) {
            self::markTestSkipped('The OpenAPI document is only available inside the billstack monorepo.');
        }
        $spec = json_decode((string) file_get_contents(self::SPEC), true, 512, JSON_THROW_ON_ERROR);
        $schemas = $spec['components']['schemas'];
        $deref = static function (array $s) use ($schemas): array {
            while (isset($s['$ref'])) {
                $s = $schemas[substr($s['$ref'], strlen('#/components/schemas/'))];
            }

            return $s;
        };
        $isObject = static function (array $s) use (&$isObject, $deref): bool {
            $s = $deref($s);
            foreach ($s['anyOf'] ?? [] as $option) {
                if ($isObject($option)) {
                    return true;
                }
            }

            return ($s['type'] ?? null) === 'object' || isset($s['additionalProperties']) || isset($s['properties']);
        };

        $expected = [];
        foreach ($spec['paths'] as $path => $ops) {
            foreach ($ops as $method => $op) {
                $schema = $op['requestBody']['content']['application/json']['schema'] ?? null;
                if (!is_array($schema)) {
                    continue;
                }
                $fields = [];
                foreach ($deref($schema)['properties'] ?? [] as $name => $prop) {
                    if ($isObject($prop)) {
                        $fields[] = (string) $name;
                    }
                }
                sort($fields);
                $expected[strtoupper($method) . ' ' . $path] = $fields;
            }
        }
        ksort($expected);

        $actual = [];
        foreach (\BillKit\Model\RequestMaps::ROUTES as $route => $paths) {
            $actual[$route] = array_values(array_filter($paths, static fn (string $p): bool => !str_contains($p, '.')));
        }
        self::assertSame($expected, $actual);
        self::assertContains('metadata', \BillKit\Model\RequestMaps::ROUTES['POST /v1/customers']);
        self::assertSame(['capabilities', 'theme'], \BillKit\Model\RequestMaps::ROUTES['POST /v1/tenant/portal_branding']);
    }

    public function testARouteTheDocumentLacksStillSendsKnownMapsAsObjects(): void
    {
        self::assertEquals(
            ['metadata' => new \stdClass(), 'tags' => []],
            \BillKit\Model\RequestMaps::apply('POST', '/v1/brand_new_things', ['metadata' => [], 'tags' => []]),
        );
        // A listed route with no object field converts nothing.
        self::assertSame(
            ['metadata' => []],
            \BillKit\Model\RequestMaps::apply('POST', '/v1/invoices/in_1/void', ['metadata' => []]),
        );
    }

    public function testABodyThatIsNotUtf8IsAnArgumentError(): void
    {
        $http = (new MockHttpClient())->stage(200, ['id' => 'cus_1']);
        $client = $this->makeClient($http);

        try {
            $client->customers->create(['email' => 'a@b.co', 'name' => "Ada \xB1\x31"]);
            self::fail('expected the body to be refused');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('cannot be encoded as JSON', $e->getMessage());
            self::assertStringContainsString('POST /v1/customers', $e->getMessage());
            self::assertStringNotContainsString('Ada', $e->getMessage());
            self::assertInstanceOf(\JsonException::class, $e->getPrevious());
        }
        self::assertCount(0, $http->requests);
    }

    public function testTestClocksRoutes(): void
    {
        $http = (new MockHttpClient())
            ->stage(200, ['id' => 'clock_1'])
            ->stage(200, ['id' => 'clock_1'])
            ->stage(200, ['id' => 'clock_1'])
            ->stage(200, ['id' => 'clock_1', 'object' => 'test_clock', 'deleted' => true])
            ->stage(200, ['object' => 'list', 'data' => [], 'has_more' => false]);
        $client = $this->makeClient($http);

        $client->testClocks->create(['frozen_time' => 1_800_000_000, 'name' => 'Renewals']);
        $client->testClocks->retrieve('clock_1');
        $client->testClocks->advance('clock_1', ['frozen_time' => 1_900_000_000], ['idempotency_key' => 'adv-1']);
        $deleted = $client->testClocks->delete('clock_1');
        $client->testClocks->all(['limit' => 5]);

        $lines = array_map(
            fn ($r): string => $r->getMethod() . ' ' . $this->url($r),
            $http->requests,
        );
        self::assertSame([
            'POST ' . self::BASE_URL . '/v1/test_clocks',
            'GET ' . self::BASE_URL . '/v1/test_clocks/clock_1',
            'POST ' . self::BASE_URL . '/v1/test_clocks/clock_1/advance',
            'DELETE ' . self::BASE_URL . '/v1/test_clocks/clock_1',
            'GET ' . self::BASE_URL . '/v1/test_clocks?limit=5',
        ], $lines);
        self::assertSame(['frozen_time' => 1_800_000_000, 'name' => 'Renewals'], $this->bodyArray($http->requests[0]));
        self::assertStringStartsWith('sdk-', $http->requests[0]->getHeaderLine('Idempotency-Key'));
        self::assertSame(['frozen_time' => 1_900_000_000], $this->bodyArray($http->requests[2]));
        self::assertSame('adv-1', $http->requests[2]->getHeaderLine('Idempotency-Key'));
        self::assertTrue($deleted['deleted']);
    }

    public function testTestClocksAutoPagingIteratorWalksPages(): void
    {
        $http = (new MockHttpClient())
            ->stage(200, ['object' => 'list', 'data' => [['id' => 'clock_2']], 'has_more' => true])
            ->stage(200, ['object' => 'list', 'data' => [['id' => 'clock_1']], 'has_more' => false]);
        $client = $this->makeClient($http);

        $ids = [];
        foreach ($client->testClocks->autoPagingIterator() as $clock) {
            self::assertInstanceOf(TestClock::class, $clock);
            $ids[] = $clock->id;
        }
        self::assertSame(['clock_2', 'clock_1'], $ids);
        self::assertStringContainsString('starting_after=clock_2', $this->url($http->requests[1]));
    }

    public function testCustomerCreatePassesTestClockThrough(): void
    {
        $http = (new MockHttpClient())->stage(200, []);
        $client = $this->makeClient($http);

        $client->customers->create(['email' => 'a@b.co', 'test_clock_id' => 'clock_1']);

        self::assertSame(['email' => 'a@b.co', 'test_clock_id' => 'clock_1'], $this->bodyArray($http->requests[0]));
    }

    public function testEventTypeConstants(): void
    {
        self::assertSame('invoice.paid', EventType::INVOICE_PAID);
        self::assertContains('subscription.updated', EventType::all());
        self::assertSame(count(EventType::all()), count(array_unique(EventType::all())));
        self::assertTrue(EventType::isKnown('invoice.paid'));
        self::assertFalse(EventType::isKnown('invoice.something_new'));
    }

    public function testUnknownEventTypeVerifiesAndKeepsPreviousAttributes(): void
    {
        $secret = 'bkwhsec_unit';
        $payload = (string) json_encode([
            'id' => 'evt_1',
            'object' => 'event',
            'type' => 'subscription.something_added_later',
            'created' => 1,
            'livemode' => false,
            'data' => ['id' => 'sub_1', 'status' => 'active'],
            'previous_attributes' => ['status' => 'trialing'],
        ]);
        $ts = time();
        $sig = hash_hmac('sha256', $ts . '.' . $payload, $secret);

        $event = Webhooks::verifySignature($payload, "t={$ts},v1={$sig}", $secret);

        self::assertFalse(EventType::isKnown($event['type']));
        // A type this SDK does not know is a plain Event, not an error.
        self::assertSame(Event::class, $event::class);
        self::assertSame(['status' => 'trialing'], $event->previous_attributes);
        self::assertSame(['id' => 'sub_1', 'status' => 'active'], $event->data->toArray());
    }
}
