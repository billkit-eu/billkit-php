<?php

declare(strict_types=1);

namespace BillKit\Tests;

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

        self::assertSame([['id' => 'cus_1']], $ids);
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

        self::assertSame([['name' => 'no-id']], $rows);
        self::assertCount(1, $http->requests);
    }

    public function testForwardsPageSizeAsLimit(): void
    {
        $http = (new MockHttpClient())
            ->stage(200, ['object' => 'list', 'data' => [], 'has_more' => false]);
        $client = $this->makeClient($http);

        iterator_to_array($client->customers->autoPagingIterator(50), false);

        self::assertStringContainsString('limit=50', (string) $http->requests[0]->getUri()->getQuery());
    }

    public function testEventsIteratorForwardsTypeFilter(): void
    {
        $http = (new MockHttpClient())
            ->stage(200, ['object' => 'list', 'data' => [], 'has_more' => false]);
        $client = $this->makeClient($http);

        iterator_to_array($client->events->autoPagingIterator(null, 'customer.created'), false);

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
}
