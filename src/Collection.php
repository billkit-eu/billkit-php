<?php

declare(strict_types=1);

namespace BillKit;

/**
 * One page of a list endpoint.
 *
 * The API returns Stripe-shape envelopes:
 *
 *     { "object": "list", "data": [...], "has_more": bool }
 *
 * `data` holds typed objects (`Collection<Customer>` from
 * `$client->customers->all()`). Iterating the collection or counting it
 * reads this page only:
 *
 *     $page = $client->customers->all(['limit' => 10]);
 *     foreach ($page as $customer) { ... }   // at most 10
 *     $page->has_more;                       // more beyond this page?
 *
 * {@see self::autoPagingIterator()} keeps going past this page, following
 * the `starting_after` cursor until the server reports no more:
 *
 *     foreach ($client->customers->all(['limit' => 100])->autoPagingIterator() as $customer) { ... }
 *
 * Array access (`$page['data']`, `$page['has_more']`) still works, as on
 * every BillKit object, and a field this SDK version does not declare is
 * reachable as `$page->field` too.
 *
 * A page can be serialised (`serialize()`, Laravel's `Cache::remember()`),
 * but the restored copy has lost its connection to the client: it holds the
 * same items, and {@see self::autoPagingIterator()} on it throws once it has
 * yielded them if the server said there was more. Cache the items, or re-run
 * the query, when you need the rest.
 *
 * @template T of BillKitObject
 *
 * @implements \ArrayAccess<array-key, mixed>
 * @implements \IteratorAggregate<int, T>
 */
final class Collection implements \ArrayAccess, \IteratorAggregate, \Countable, \JsonSerializable
{
    public readonly string $object;

    /** @var list<T> */
    public readonly array $data;

    public readonly bool $has_more;

    /** @var array<string, mixed> */
    private readonly array $rawValues;

    /**
     * @internal built by the resource methods; use {@see self::constructFrom()} in tests
     *
     * @param array<array-key, mixed>                           $page
     * @param class-string<T>                                   $itemClass
     * @param (\Closure(array<string, mixed>): Collection<T>)|null $fetchPage
     * @param array<string, mixed>                              $params    the filters this page was fetched with
     */
    public function __construct(
        array $page,
        string $itemClass,
        private readonly ?\Closure $fetchPage = null,
        private readonly array $params = [],
    ) {
        $items = [];
        $rawItems = $page['data'] ?? [];
        if (is_array($rawItems)) {
            foreach ($rawItems as $item) {
                if (is_array($item)) {
                    $items[] = $itemClass::constructFrom($item);
                }
            }
        }

        $raw = [];
        foreach ($page as $key => $value) {
            $raw[(string) $key] = $value;
        }
        $raw['data'] = $items;

        $this->rawValues = $raw;
        $this->data = $items;
        $this->object = is_string($page['object'] ?? null) ? $page['object'] : 'list';
        $this->has_more = ($page['has_more'] ?? false) === true;
    }

    /**
     * Build a page from a decoded JSON array, for example a fixture in your
     * own tests. A collection built this way cannot fetch further pages.
     *
     * @template TItem of BillKitObject
     *
     * @param array<array-key, mixed> $page
     * @param class-string<TItem>     $itemClass
     *
     * @return self<TItem>
     */
    public static function constructFrom(array $page, string $itemClass = BillKitObject::class): self
    {
        return new self($page, $itemClass);
    }

    /**
     * Every item from this page onwards, fetching the following pages as the
     * loop reaches them.
     *
     * The walk carries the filters and request options the first page was
     * fetched with, so a filtered walk stays filtered server-side. Three
     * things end it, in this order: `has_more` false (the server's answer),
     * an empty page (a guard against a proxy that would otherwise loop
     * forever), and a last item with no `id` to continue from.
     *
     * A page that cannot fetch (built with {@see self::constructFrom()}, or
     * restored by `unserialize()`) yields its own items and then throws a
     * `\LogicException` if `has_more` is true, rather than ending the loop
     * as if the list were complete.
     *
     * @return \Generator<int, T>
     */
    public function autoPagingIterator(): \Generator
    {
        $page = $this;
        while (true) {
            foreach ($page->data as $item) {
                yield $item;
            }

            if (!$page->has_more || $page->data === []) {
                return;
            }
            if ($page->fetchPage === null) {
                throw new \LogicException(
                    'BillKit: this page has more results but cannot fetch them: it was built with '
                    . 'Collection::constructFrom() or restored from serialize(). Run the list call again to continue.',
                );
            }
            $last = $page->data[count($page->data) - 1];
            $cursor = $last['id'];
            if (!is_string($cursor) || $cursor === '') {
                return;
            }
            $page = ($page->fetchPage)(array_merge($page->params, ['starting_after' => $cursor]));
        }
    }

    /** True when this page holds no items. */
    public function isEmpty(): bool
    {
        return $this->data === [];
    }

    /**
     * The page as a plain array, items included, exactly as 0.x returned it.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = $this->rawValues;
        $out['data'] = array_map(static fn (BillKitObject $item): array => $item->toArray(), $this->data);

        return $out;
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->rawValues[(string) $offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->rawValues[(string) $offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('BillKit objects are read-only. Copy the data with toArray() if you need to change it.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('BillKit objects are read-only. Copy the data with toArray() if you need to change it.');
    }

    /**
     * This page's items. Use {@see self::autoPagingIterator()} for every page.
     *
     * @return \ArrayIterator<int, T>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->data);
    }

    /** The number of items on this page, not in the whole list. */
    public function count(): int
    {
        return count($this->data);
    }

    /**
     * The page as JSON-ready data; each item serialises itself, so an empty
     * `metadata` stays `{}` (see {@see BillKitObject::jsonSerialize()}).
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->rawValues;
    }

    /**
     * A field this SDK version does not declare, as on {@see BillKitObject}.
     */
    public function __get(string $name): mixed
    {
        return $this->rawValues[$name] ?? null;
    }

    public function __isset(string $name): bool
    {
        return isset($this->rawValues[$name]);
    }

    public function __set(string $name, mixed $value): void
    {
        throw new \LogicException("BillKit objects are read-only; cannot set \${$name}.");
    }

    public function __unset(string $name): void
    {
        throw new \LogicException("BillKit objects are read-only; cannot unset \${$name}.");
    }

    /**
     * What `serialize()` stores: the page and its filters, never the
     * closure that fetches the next page (a closure cannot be serialised).
     *
     * @return array{raw: array<string, mixed>, params: array<string, mixed>}
     */
    public function __serialize(): array
    {
        return ['raw' => $this->rawValues, 'params' => $this->params];
    }

    /**
     * Restore a page from {@see self::__serialize()}. It cannot fetch further
     * pages; see {@see self::autoPagingIterator()}.
     *
     * @param array<array-key, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        $raw = is_array($data['raw'] ?? null) ? $data['raw'] : [];
        $items = [];
        foreach (is_array($raw['data'] ?? null) ? $raw['data'] : [] as $item) {
            if ($item instanceof BillKitObject) {
                /** @var T $item */
                $items[] = $item;
            }
        }
        $values = [];
        foreach ($raw as $key => $value) {
            $values[(string) $key] = $value;
        }
        $values['data'] = $items;

        $this->rawValues = $values;
        $this->data = $items;
        $this->object = is_string($values['object'] ?? null) ? $values['object'] : 'list';
        $this->has_more = ($values['has_more'] ?? false) === true;
        $this->fetchPage = null;
        $params = [];
        foreach (is_array($data['params'] ?? null) ? $data['params'] : [] as $key => $value) {
            $params[(string) $key] = $value;
        }
        $this->params = $params;
    }
}
