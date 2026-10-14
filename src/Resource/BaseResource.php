<?php

declare(strict_types=1);

namespace BillKit\Resource;

use BillKit\BillKitObject;
use BillKit\Collection;
use BillKit\RequestOptions;
use BillKit\Transport;

/**
 * Shared plumbing for every resource family.
 *
 * Every public resource method has one of two shapes:
 *
 *     verb(array $params = [], array $opts = [])
 *     verb(string $id, array $params = [], array $opts = [])
 *
 * `$params` is what goes to the API (the JSON body of a POST, the query
 * string of a GET) under the API's own snake_case names. `$opts` is how the
 * SDK sends it; see {@see RequestOptions} for the keys.
 *
 * @internal Reach resources through {@see \BillKit\BillKitClient}; this class
 *           and its constructor are not part of the public API.
 */
abstract class BaseResource
{
    /**
     * @internal
     */
    final public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * Percent-encode a caller-supplied id before it becomes a path segment.
     *
     * Ids reach the SDK from the caller's own storage, and one carrying
     * ``/``, ``?`` or ``#`` would otherwise rewrite the request: ``#``
     * truncates the path, ``?`` turns the tail into a query string, and
     * ``/`` walks to a different route entirely. Encoding keeps the request
     * on the route the method names, so a bad id is a clean ``404`` rather
     * than a call somewhere else.
     *
     * An empty or whitespace-only id is refused with an
     * ``\InvalidArgumentException`` before any request: it would encode to
     * nothing and leave ``/v1/customers/``, a different route rather than a
     * lookup that 404s. Every id-bearing path goes through here, so this one
     * check covers them all.
     *
     * @throws \InvalidArgumentException when ``$id`` is empty or blank
     */
    protected static function p(string $id): string
    {
        if (trim($id) === '') {
            throw new \InvalidArgumentException(
                sprintf("BillKit: an id is required here, got '%s'. Pass the object's id (for example 'cus_...').", $id),
            );
        }
        // `.` and `..` survive percent-encoding unchanged and are resolved as
        // dot segments by URL normalisation: `customers->retrieve('..')`
        // would become `GET /v1/`, a different route rather than a 404.
        if ($id === '.' || $id === '..') {
            throw new \InvalidArgumentException(
                sprintf("BillKit: '%s' is not an id. Pass the object's id (for example 'cus_...').", $id),
            );
        }

        return rawurlencode($id);
    }

    /**
     * GET one object.
     *
     * @template T of BillKitObject
     *
     * @param array<string, mixed>    $params query parameters (`expand`, ...)
     * @param array<array-key, mixed> $opts
     * @param class-string<T>         $class
     *
     * @return T
     */
    protected function getObject(string $path, array $params, array $opts, string $class): BillKitObject
    {
        $raw = $this->transport->request('GET', $path, self::query($params), null, RequestOptions::parse($opts));

        return $class::constructFrom($raw);
    }

    /**
     * GET one page of a list.
     *
     * @template T of BillKitObject
     *
     * @param array<string, mixed>    $params filters, `limit`, `starting_after`, `expand`
     * @param array<array-key, mixed> $opts
     * @param class-string<T>         $itemClass
     *
     * @return Collection<T>
     */
    protected function getList(string $path, array $params, array $opts, string $itemClass): Collection
    {
        $parsed = RequestOptions::parse($opts);
        $query = self::query($params);
        $raw = $this->transport->request('GET', $path, $query, null, $parsed);
        unset($query['starting_after']);

        return new Collection(
            $raw,
            $itemClass,
            fn (array $next): Collection => $this->getList($path, $next, $opts, $itemClass),
            $query,
        );
    }

    /**
     * POST with a JSON body built from `$params`: `null` values are dropped
     * (`false`, `0` and `''` are kept), except on the `$clearable` keys,
     * where an explicit `null` is the value that clears the field.
     *
     * @template T of BillKitObject
     *
     * @param array<string, mixed>    $params
     * @param array<array-key, mixed> $opts
     * @param class-string<T>         $class
     * @param list<string>            $clearable
     *
     * @return T
     */
    protected function postObject(
        string $path,
        array $params,
        array $opts,
        string $class,
        array $clearable = [],
    ): BillKitObject {
        $body = self::body($params);
        foreach ($clearable as $key) {
            if (array_key_exists($key, $params)) {
                $body[$key] = $params[$key];
            }
        }

        return $this->send('POST', $path, $body, $opts, $class);
    }

    /**
     * POST a body exactly as given: no `null` stripping. For the few routes
     * whose body the resource builds itself.
     *
     * @template T of BillKitObject
     *
     * @param array<string, mixed>    $body
     * @param array<array-key, mixed> $opts
     * @param class-string<T>         $class
     *
     * @return T
     */
    protected function postFixed(string $path, array $body, array $opts, string $class): BillKitObject
    {
        return $this->send('POST', $path, $body, $opts, $class);
    }

    /**
     * POST a lifecycle verb (cancel, revoke, rotate_secret, ...). With no
     * params the request has no body at all, which is what these routes
     * have always been sent.
     *
     * @template T of BillKitObject
     *
     * @param array<string, mixed>    $params
     * @param array<array-key, mixed> $opts
     * @param class-string<T>         $class
     *
     * @return T
     */
    protected function postAction(string $path, array $params, array $opts, string $class): BillKitObject
    {
        $body = self::body($params);

        return $this->send('POST', $path, $body === [] ? null : $body, $opts, $class);
    }

    /**
     * DELETE.
     *
     * @template T of BillKitObject
     *
     * @param array<string, mixed>    $params
     * @param array<array-key, mixed> $opts
     * @param class-string<T>         $class
     *
     * @return T
     */
    protected function deleteObject(string $path, array $params, array $opts, string $class): BillKitObject
    {
        $raw = $this->transport->request('DELETE', $path, self::query($params), null, RequestOptions::parse($opts));

        return $class::constructFrom($raw);
    }

    /**
     * GET raw bytes (a PDF, an export file).
     *
     * @param array<array-key, mixed> $opts
     */
    protected function getBytes(string $path, array $opts): string
    {
        return $this->transport->requestBytes('GET', $path, RequestOptions::parse($opts));
    }

    /**
     * The JSON body for `$params`, with `null` values dropped.
     *
     * `idempotency_key` in params is refused: in 1.0 it is a request option.
     * Sending it as a body field would be rejected by the API, and quietly
     * dropping it would send the request without the key the caller meant to
     * protect it with.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    protected static function body(array $params): array
    {
        self::refuseIdempotencyKey($params);

        return array_filter($params, static fn ($v): bool => $v !== null);
    }

    /**
     * The query for `$params`. Every key is forwarded, including one this
     * SDK version does not know: a filter the API adds in a later 1.x has
     * to be usable from an older client, as it is from node (its params
     * object is forwarded) and python (through `extra_query=`). A
     * key the route really does not take is the API's `400
     * parameter_unknown`, naming it. Values are still type-checked when
     * the query string is built (see `Transport::buildQuery()`).
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException for `idempotency_key`
     */
    private static function query(array $params): array
    {
        self::refuseIdempotencyKey($params);

        return $params;
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function refuseIdempotencyKey(array $params): void
    {
        if (array_key_exists('idempotency_key', $params)) {
            throw new \InvalidArgumentException(
                'BillKit: pass idempotency_key in the request options (the last argument), not in the params: '
                . "->create(\$params, ['idempotency_key' => '...']).",
            );
        }
    }

    /**
     * @template T of BillKitObject
     *
     * @param array<string, mixed>|null $body
     * @param array<array-key, mixed>   $opts
     * @param class-string<T>           $class
     *
     * @return T
     */
    private function send(string $method, string $path, ?array $body, array $opts, string $class): BillKitObject
    {
        $raw = $this->transport->request($method, $path, [], $body, RequestOptions::parse($opts));

        return $class::constructFrom($raw);
    }
}
