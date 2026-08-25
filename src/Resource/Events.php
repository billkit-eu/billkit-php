<?php

declare(strict_types=1);

namespace BillKit\Resource;

use BillKit\Collection;

/** Read-only access to the tenant event stream. */
final class Events extends BaseResource
{
    /**
     * Fetch a single event by id.
     *
     * @return array<string, mixed>
     */
    public function retrieve(string $id): array
    {
        return $this->get("/v1/events/{$id}");
    }

    /**
     * List one page of events. Use {@see self::autoPagingIterator()} to
     * walk every page.
     *
     * @param array<string, scalar|null> $params
     *
     * @return array<string, mixed>
     */
    public function all(array $params = []): array
    {
        return $this->get('/v1/events', $params);
    }

    /**
     * Yield every event across all pages. Pass ``$type`` (e.g.
     * ``customer.created``) to filter server-side.
     *
     * @return \Generator<int, mixed>
     */
    public function autoPagingIterator(?int $pageSize = null, ?string $type = null): \Generator
    {
        yield from Collection::autoPagingIterator(
            fn (array $p): array => $this->get('/v1/events', $p),
            $pageSize,
            ['type' => $type],
        );
    }
}
