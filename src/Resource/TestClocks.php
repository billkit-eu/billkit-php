<?php

declare(strict_types=1);

namespace BillKit\Resource;

use BillKit\Collection;
use BillKit\Model\DeletedResource;
use BillKit\Model\TestClock;

/**
 * Test clocks: move time forward in test mode. Test-mode keys only.
 *
 * @internal constructed by {@see \BillKit\BillKitClient}; use `$client->testClocks`.
 */
final class TestClocks extends BaseResource
{
    /**
     * Create a clock frozen at `frozen_time` (Unix seconds), with an optional
     * `name`. Attach a customer to it with
     * `$client->customers->create(['test_clock_id' => $clock->id])`.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return TestClock
     */
    public function create(array $params = [], array $opts = []): TestClock
    {
        return $this->postObject('/v1/test_clocks', $params, $opts, TestClock::class);
    }

    /**
     * One clock and the time it is frozen at.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return TestClock
     */
    public function retrieve(string $id, array $params = [], array $opts = []): TestClock
    {
        return $this->getObject('/v1/test_clocks/' . self::p($id), $params, $opts, TestClock::class);
    }

    /**
     * Move the clock forward to `frozen_time` and run what falls due.
     *
     * `frozen_time` must be later than the clock's current time. The work is
     * done before this returns. The SDK always sends an `Idempotency-Key`, so
     * its own retries replay rather than advance twice.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return TestClock
     */
    public function advance(string $id, array $params = [], array $opts = []): TestClock
    {
        return $this->postObject('/v1/test_clocks/' . self::p($id) . '/advance', $params, $opts, TestClock::class);
    }

    /**
     * Delete a clock. Returns a {@see DeletedResource}, not the clock. Its
     * customers are not deleted; they return to real time.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return DeletedResource
     */
    public function delete(string $id, array $params = [], array $opts = []): DeletedResource
    {
        return $this->deleteObject('/v1/test_clocks/' . self::p($id), $params, $opts, DeletedResource::class);
    }

    /**
     * One page of clocks, newest first.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return Collection<TestClock>
     */
    public function all(array $params = [], array $opts = []): Collection
    {
        return $this->getList('/v1/test_clocks', $params, $opts, TestClock::class);
    }

    /**
     * Every item across all pages.
     *
     * Takes the same `$params` as {@see self::all()}; the filters are carried
     * onto every page request, so a filtered walk narrows server-side. `limit`
     * sets the page size.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts   idempotency_key, timeout, max_retries, headers
     *
     * @return \Generator<int, TestClock>
     */
    public function autoPagingIterator(array $params = [], array $opts = []): \Generator
    {
        yield from $this->all($params, $opts)->autoPagingIterator();
    }
}
