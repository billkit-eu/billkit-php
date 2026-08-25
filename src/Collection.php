<?php

declare(strict_types=1);

namespace BillKit;

/**
 * Auto-pagination helper for list endpoints.
 *
 * The BillKit API returns Stripe-shape envelopes:
 *
 *     { "object": "list", "data": [...], "has_more": bool }
 *
 * Cursor pagination is forward-only via the last item's ``id`` as
 * ``starting_after``. {@see self::autoPagingIterator()} walks every page
 * and yields each row so callers can ``foreach`` across the whole result
 * set without managing cursors:
 *
 *     foreach ($client->customers->autoPagingIterator() as $customer) {
 *         // ...
 *     }
 */
final class Collection
{
    /**
     * Walk every page produced by ``$listFn`` and yield each row.
     *
     * Three terminators, in priority order (a direct port of the Node
     * SDK's ``paginate``):
     *   1. ``has_more = false``: the server's authoritative signal.
     *   2. Empty ``data``: guards against a server/proxy bug that would
     *      otherwise loop forever.
     *   3. The last row has no ``id``, so there is no cursor to advance with.
     *
     * @param callable(array<string, scalar|null>): array<string, mixed> $listFn
     * @param array<string, scalar|null>                                 $filters
     *
     * @return \Generator<int, mixed>
     */
    public static function autoPagingIterator(
        callable $listFn,
        ?int $pageSize = null,
        array $filters = [],
    ): \Generator {
        $cleanFilters = array_filter($filters, static fn ($v): bool => $v !== null);
        $cursor = null;

        while (true) {
            $params = $cleanFilters;
            if ($pageSize !== null) {
                $params['limit'] = $pageSize;
            }
            if ($cursor !== null) {
                $params['starting_after'] = $cursor;
            }

            $page = $listFn($params);
            $items = $page['data'] ?? [];
            if (!is_array($items)) {
                return;
            }
            foreach ($items as $item) {
                yield $item;
            }

            if (empty($page['has_more']) || count($items) === 0) {
                return;
            }
            $last = end($items);
            $cursor = is_array($last) && isset($last['id']) && is_string($last['id']) ? $last['id'] : null;
            if ($cursor === null) {
                return;
            }
        }
    }
}
