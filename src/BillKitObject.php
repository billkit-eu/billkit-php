<?php

declare(strict_types=1);

namespace BillKit;

/**
 * Base class of every object the API returns.
 *
 * A response is decoded into a subclass of this (`BillKit\Model\Customer`,
 * `BillKit\Model\Subscription`, ...), generated from the published OpenAPI
 * document. Each known field is a typed, read-only public property, so
 * `$customer->email` is a `?string` your IDE and static analyser know about.
 *
 * The same object is also read-only array access over the raw response, and
 * both styles are permanent parts of the API:
 *
 *     $customer->email;        // typed property
 *     $customer['email'];      // array access, the 0.x style
 *     $customer->toArray();    // a plain array, recursively
 *     json_encode($customer);  // the JSON the API sent, see below
 *
 * **Forward compatibility.** The API adds fields and enum values without a
 * new SDK release. A field this SDK version does not know is kept and
 * reachable as `$obj['new_field']` and `$obj->new_field`, and an enum field
 * is a plain `string`, so a value added later is just a string you have not
 * seen before. Decoding a response never throws.
 *
 * **Every typed property is nullable, and reading one never throws.** A
 * property is `null` when the payload lacks the field or carries a value of
 * the wrong type (the raw value is still in `$obj['field']`). That covers
 * fields the schema calls required, `id` and `object` included, because not
 * every payload is a live response: an event's `data` is the object as it
 * was recorded, `events->retrieve()` and webhook redeliveries serve that
 * stored snapshot unchanged, and a snapshot recorded before a field existed
 * does not have it. Check for `null` where a missing value matters.
 *
 * Objects are immutable. Writing through array access, or to a field the
 * class does not declare, throws `\LogicException`; assigning a declared
 * property is a PHP readonly violation and throws `\Error` ("Cannot modify
 * readonly property"). Copy with `toArray()` when you need to change
 * something.
 *
 * **JSON.** `json_encode($obj)` writes the response back out. The JSON-object
 * fields the class declares (`metadata`, ...) are written as `{}` when empty,
 * as the API sent them. `toArray()` cannot keep that distinction, because
 * PHP has one array type: an empty `metadata` is `[]` there, and so is an
 * empty object in a field this SDK version does not know, in `json_encode()`
 * output too.
 *
 * @implements \ArrayAccess<array-key, mixed>
 * @implements \IteratorAggregate<string, mixed>
 *
 * @phpstan-consistent-constructor
 */
class BillKitObject implements \ArrayAccess, \JsonSerializable, \IteratorAggregate, \Countable
{
    /**
     * Nested fields that decode into a model class: `field => Class::class`
     * for a single object, `field => [Class::class]` for a list of them.
     * Generated subclasses fill it in.
     *
     * @var array<string, class-string<BillKitObject>|array{0: class-string<BillKitObject>}>
     */
    protected const NESTED = [];

    /**
     * Fields that are JSON objects decoded into PHP arrays, so that
     * {@see self::jsonSerialize()} can write an empty one as `{}`.
     * Generated subclasses fill it in.
     *
     * @var list<string>
     */
    protected const MAP_FIELDS = [];

    /** @var array<string, mixed> */
    private readonly array $rawValues;

    /**
     * Generated subclasses extend this constructor to set their typed
     * properties; every one keeps this signature.
     *
     * @param array<array-key, mixed> $values the decoded JSON object
     */
    public function __construct(array $values = [])
    {
        $normalised = [];
        foreach ($values as $key => $value) {
            $normalised[(string) $key] = $value;
        }
        $this->rawValues = static::convertValues($normalised);
    }

    /**
     * Build an object from a decoded JSON array, for example a fixture in
     * your own tests:
     *
     *     $customer = Customer::constructFrom(['id' => 'cus_1', 'email' => null]);
     *
     * @param array<array-key, mixed> $values
     */
    public static function constructFrom(array $values): static
    {
        return new static($values);
    }

    /**
     * The object as a plain array, recursively: nested objects become arrays
     * too. This is exactly what the 0.x SDK returned.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [];
        foreach ($this->rawValues as $key => $value) {
            $out[$key] = self::unwrap($value);
        }

        return $out;
    }

    /**
     * Whether the response carried `$key` at all, even as `null`.
     *
     * `isset($obj['key'])` follows PHP's array rules and is false for a
     * present `null`; this is the `array_key_exists()` question, which does
     * not accept an object.
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->rawValues);
    }

    /**
     * The field names the response carried.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->rawValues);
    }

    // ArrayAccess

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
        throw new \LogicException(
            'BillKit objects are read-only. Copy the data with toArray() if you need to change it.',
        );
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException(
            'BillKit objects are read-only. Copy the data with toArray() if you need to change it.',
        );
    }

    // Property access for fields this SDK version does not declare.

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
     * The response as JSON-ready data. An empty JSON-object field
     * ({@see self::MAP_FIELDS}) becomes an empty `\stdClass`, so it encodes
     * as `{}` and not `[]`; nested objects serialise themselves the same way.
     */
    public function jsonSerialize(): mixed
    {
        $out = $this->rawValues;
        foreach (static::MAP_FIELDS as $field) {
            if (($out[$field] ?? null) === []) {
                $out[$field] = new \stdClass();
            }
        }

        return $out === [] ? new \stdClass() : $out;
    }

    /**
     * Iterate the response's fields, `field => value`.
     *
     * @return \ArrayIterator<string, mixed>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->rawValues);
    }

    public function count(): int
    {
        return count($this->rawValues);
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return $this->toArray();
    }

    /**
     * The raw (already converted) value of a field, for generated constructors.
     */
    protected function raw(string $key): mixed
    {
        return $this->rawValues[$key] ?? null;
    }

    /**
     * Turn the nested fields named in {@see self::NESTED} into objects.
     *
     * A value of the wrong shape is left as it is, never thrown on.
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    protected static function convertValues(array $values): array
    {
        foreach (static::NESTED as $key => $class) {
            if (!isset($values[$key]) || !is_array($values[$key])) {
                continue;
            }
            if (is_array($class)) {
                $items = [];
                foreach ($values[$key] as $item) {
                    $items[] = is_array($item) ? $class[0]::constructFrom($item) : $item;
                }
                $values[$key] = $items;
            } else {
                $values[$key] = $class::constructFrom($values[$key]);
            }
        }

        return $values;
    }

    // Coercion helpers for the generated hydrators. Each returns null for a
    // value of the wrong type rather than throwing.

    protected static function asString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    protected static function asInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        // JSON has one number type, and an encoder may write 3 as 3.0.
        if (is_float($value) && floor($value) === $value && abs($value) <= PHP_INT_MAX) {
            return (int) $value;
        }

        return null;
    }

    protected static function asFloat(mixed $value): ?float
    {
        return is_int($value) || is_float($value) ? (float) $value : null;
    }

    protected static function asBool(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected static function asMap(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $out = [];
        foreach ($value as $key => $item) {
            $out[(string) $key] = $item;
        }

        return $out;
    }

    /**
     * @return array<string, string>|null
     */
    protected static function asStringMap(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $out = [];
        foreach ($value as $key => $item) {
            if (is_string($item)) {
                $out[(string) $key] = $item;
            }
        }

        return $out;
    }

    /**
     * @return list<string>|null
     */
    protected static function asStringList(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        return array_values(array_filter($value, 'is_string'));
    }

    /**
     * @return list<int>|null
     */
    protected static function asIntList(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        return array_values(array_filter($value, 'is_int'));
    }

    /**
     * @template T of BillKitObject
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    protected static function asObject(mixed $value, string $class): ?BillKitObject
    {
        return $value instanceof $class ? $value : null;
    }

    /**
     * @template T of BillKitObject
     *
     * @param class-string<T> $class
     *
     * @return list<T>|null
     */
    protected static function asObjectList(mixed $value, string $class): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $out = [];
        foreach ($value as $item) {
            if ($item instanceof $class) {
                $out[] = $item;
            }
        }

        return $out;
    }

    private static function unwrap(mixed $value): mixed
    {
        if ($value instanceof self) {
            return $value->toArray();
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = self::unwrap($item);
            }

            return $out;
        }

        return $value;
    }
}
