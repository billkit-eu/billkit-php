<?php

declare(strict_types=1);

namespace BillKit\Tests;

use BillKit\Collection;
use BillKit\Model\Customer;
use BillKit\Model\Event;
use BillKit\Model\SubscriptionUpdatedEvent;
use BillKit\Tests\Support\MockHttpClient;

final class PaginationTest extends BillKitTestCase
{
    public function testWalksEveryPageAndAdvancesCursor(): void
    {
        $http = (new MockHttpClient())
            ->stage(200, ['object' => 'list', 'data' => [['id' => 'cus_1'], ['id' => 'cus_2']], 'has_more' => true])
            ->stage(200, ['object' => 'list', 'data' => [['id' => 'cus_3']], 'has_more' => false]);
        $client = $this->makeClient($http);

        $ids = [];
        foreach ($client->customers->autoPagingIterator() as $customer) {
            $ids[] = $customer['id'];
        }

        self::assertSame(['cus_1', 'cus_2', 'cus_3'], $ids);
        self::assertCount(2, $http->requests);
        self::assertStringContainsString('starting_after=cus_2', (string) $http->requests[1]->getUri()->getQuery());
    }

    public function testTerminatesOnSinglePage(): void
    {
        $http = (new MockHttpClient())
            ->stage(200, ['object' => 'list', 'data' => [['id' => 'cus_1']], 'has_more' => false]);
        $client = $this->makeClient($http);

        $ids = iterator_to_array($client->customers->autoPagingIterator(), false);

        self::assertSame([['id' => 'cus_1']], array_map(static fn ($c) => $c->toArray(), $ids));
        self::assertCount(1, $http->requests);
    }

    public function testTerminatesOnEmptyDataEvenIfHasMoreTrue(): void
    {
        $http = (new MockHttpClient())
            ->stage(200, ['object' => 'list', 'data' => [], 'has_more' => true]);
        $client = $this->makeClient($http);

        $ids = iterator_to_array($client->customers->autoPagingIterator(), false);

        self::assertSame([], $ids);
        self::assertCount(1, $http->requests);
    }

    public function testTerminatesWhenLastItemHasNoId(): void
    {
        // has_more says "keep going" but there's no cursor to advance with,
        // so the iterator must stop instead of looping forever.
        $http = (new MockHttpClient())
            ->stage(200, ['object' => 'list', 'data' => [['name' => 'no-id']], 'has_more' => true]);
        $client = $this->makeClient($http);

        $rows = iterator_to_array($client->customers->autoPagingIterator(), false);

        self::assertSame([['name' => 'no-id']], array_map(static fn ($c) => $c->toArray(), $rows));
        self::assertCount(1, $http->requests);
    }

    public function testForwardsPageSizeAsLimit(): void
    {
        $http = (new MockHttpClient())
            ->stage(200, ['object' => 'list', 'data' => [], 'has_more' => false]);
        $client = $this->makeClient($http);

        iterator_to_array($client->customers->autoPagingIterator(['limit' => 50]), false);

        self::assertStringContainsString('limit=50', (string) $http->requests[0]->getUri()->getQuery());
    }

    public function testEventsIteratorForwardsTypeFilter(): void
    {
        $http = (new MockHttpClient())
            ->stage(200, ['object' => 'list', 'data' => [], 'has_more' => false]);
        $client = $this->makeClient($http);

        iterator_to_array($client->events->autoPagingIterator(['type' => 'customer.created']), false);

        self::assertStringContainsString('type=customer.created', urldecode((string) $http->requests[0]->getUri()->getQuery()));
    }

    public function testEventsIteratorOmitsNullTypeFilter(): void
    {
        $http = (new MockHttpClient())
            ->stage(200, ['object' => 'list', 'data' => [], 'has_more' => false]);
        $client = $this->makeClient($http);

        iterator_to_array($client->events->autoPagingIterator(), false);

        self::assertStringNotContainsString('type=', (string) $http->requests[0]->getUri()->getQuery());
    }

    public function testAllReturnsATypedCollectionOfThisPageOnly(): void
    {
        $http = (new MockHttpClient())
            ->stage(200, ['object' => 'list', 'data' => [['id' => 'cus_1', 'object' => 'customer']], 'has_more' => true]);
        $client = $this->makeClient($http);

        $page = $client->customers->all(['limit' => 1]);

        self::assertInstanceOf(Collection::class, $page);
        self::assertCount(1, $page);
        self::assertTrue($page->has_more);
        self::assertInstanceOf(Customer::class, $page->data[0]);
        self::assertSame('cus_1', $page->data[0]->id);
        // The 0.x array shape still reads.
        self::assertSame('cus_1', $page['data'][0]['id']);
        self::assertTrue($page['has_more']);
        foreach ($page as $customer) {
            self::assertSame('cus_1', $customer->id);
        }
        self::assertCount(1, $http->requests, 'iterating a page must not fetch the next one');
    }

    public function testCollectionAutoPagingIteratorContinuesWithTheSameFiltersAndOptions(): void
    {
        $http = (new MockHttpClient())
            ->stage(200, ['object' => 'list', 'data' => [['id' => 'sub_1']], 'has_more' => true])
            ->stage(200, ['object' => 'list', 'data' => [['id' => 'sub_2']], 'has_more' => false]);
        $client = $this->makeClient($http);

        $page = $client->subscriptions->all(
            ['status' => 'active', 'limit' => 1],
            ['headers' => ['X-Trace' => 't-1']],
        );
        $ids = [];
        foreach ($page->autoPagingIterator() as $sub) {
            $ids[] = $sub->id;
        }

        self::assertSame(['sub_1', 'sub_2'], $ids);
        $second = urldecode($http->requests[1]->getUri()->getQuery());
        self::assertStringContainsString('status=active', $second);
        self::assertStringContainsString('limit=1', $second);
        self::assertStringContainsString('starting_after=sub_1', $second);
        self::assertSame('t-1', $http->requests[1]->getHeaderLine('X-Trace'));
    }

    public function testAutoPagingIteratorHonoursACallerStartingAfter(): void
    {
        $http = (new MockHttpClient())
            ->stage(200, ['object' => 'list', 'data' => [['id' => 'cus_9']], 'has_more' => true])
            ->stage(200, ['object' => 'list', 'data' => [['id' => 'cus_8']], 'has_more' => false]);
        $client = $this->makeClient($http);

        iterator_to_array($client->customers->autoPagingIterator(['starting_after' => 'cus_10']), false);

        self::assertStringContainsString('starting_after=cus_10', $http->requests[0]->getUri()->getQuery());
        $second = $http->requests[1]->getUri()->getQuery();
        self::assertStringContainsString('starting_after=cus_9', $second);
        self::assertStringNotContainsString('cus_10', $second);
    }

    public function testEventListDecodesEachItemIntoItsTypedClass(): void
    {
        $http = (new MockHttpClient())->stage(200, ['object' => 'list', 'has_more' => false, 'data' => [
            ['id' => 'evt_1', 'object' => 'event', 'type' => 'subscription.updated', 'created' => 1,
                'livemode' => false, 'previous_attributes' => null,
                'data' => ['id' => 'sub_1', 'object' => 'subscription', 'status' => 'active']],
            ['id' => 'evt_2', 'object' => 'event', 'type' => 'thing.invented_later', 'created' => 2,
                'livemode' => false, 'previous_attributes' => null, 'data' => ['id' => 'x_1', 'object' => 'thing']],
        ]]);
        $client = $this->makeClient($http);

        $events = $client->events->all()->data;

        self::assertInstanceOf(SubscriptionUpdatedEvent::class, $events[0]);
        self::assertSame('active', $events[0]->data->status);
        self::assertSame(Event::class, $events[1]::class);
        self::assertSame('x_1', $events[1]->data['id']);
    }

    public function testAPageSurvivesSerializeWithoutItsFetcher(): void
    {
        $http = (new MockHttpClient())
            ->stage(200, ['object' => 'list', 'data' => [['id' => 'cus_1', 'object' => 'customer', 'metadata' => []]], 'has_more' => true, 'next_cursor' => 'x']);
        $page = $this->makeClient($http)->customers->all(['limit' => 1]);

        // What Laravel's Cache::remember() does with a value.
        $restored = unserialize(serialize($page));

        self::assertInstanceOf(Collection::class, $restored);
        self::assertTrue($restored->has_more);
        self::assertSame('list', $restored->object);
        self::assertInstanceOf(Customer::class, $restored->data[0]);
        self::assertSame('cus_1', $restored->data[0]->id);
        self::assertSame($page->toArray(), $restored->toArray());
        self::assertSame(json_encode($page), json_encode($restored));
        self::assertSame('x', $restored->next_cursor);

        // It cannot fetch the next page, and says so instead of ending the
        // walk as if the list were complete.
        $seen = [];
        try {
            foreach ($restored->autoPagingIterator() as $customer) {
                $seen[] = $customer->id;
            }
            self::fail('a restored page with has_more ended its walk silently');
        } catch (\LogicException $e) {
            self::assertStringContainsString('cannot fetch them', $e->getMessage());
        }
        self::assertSame(['cus_1'], $seen);
        self::assertCount(1, $http->requests);
    }

    public function testAnUnknownPageFieldIsReachableAsAProperty(): void
    {
        $page = Collection::constructFrom(['object' => 'list', 'data' => [], 'has_more' => false, 'total_count' => 7]);

        self::assertSame(7, $page->total_count);
        self::assertTrue(isset($page->total_count));
        self::assertNull($page->no_such_field);
        self::assertFalse(isset($page->no_such_field));
        $this->expectException(\LogicException::class);
        $page->total_count = 8;
    }

    public function testACompleteRestoredPageWalksWithoutThrowing(): void
    {
        $page = Collection::constructFrom(['object' => 'list', 'data' => [['id' => 'cus_1']], 'has_more' => false], Customer::class);
        $restored = unserialize(serialize($page));

        self::assertInstanceOf(Collection::class, $restored);
        self::assertSame(['cus_1'], array_map(static fn ($c) => $c->id, iterator_to_array($restored->autoPagingIterator(), false)));
    }
}
