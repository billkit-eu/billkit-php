<?php

declare(strict_types=1);

namespace BillKit\Tests;

use BillKit\BillKitClient;
use BillKit\Resource\BaseResource;
use BillKit\Tests\Support\MockHttpClient;

/**
 * One calling convention across every resource method, checked by
 * reflection so a new method cannot drift from it.
 *
 *     verb(array $params = [], array $opts = [])
 *     verb(string $id, array $params = [], array $opts = [])
 *
 * PHP named arguments make parameter names part of the API, so the names are
 * checked too.
 */
final class ConventionsTest extends BillKitTestCase
{
    /**
     * @return list<array{0: string, 1: \ReflectionMethod}>
     */
    private function publicMethods(): array
    {
        $client = $this->makeClient(new MockHttpClient());
        $out = [];
        foreach ((new \ReflectionClass($client))->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $resource = $prop->getValue($client);
            self::assertInstanceOf(BaseResource::class, $resource);
            $class = new \ReflectionClass($resource);
            self::assertTrue($class->isFinal(), "{$class->getName()} must be final");
            foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() === BaseResource::class) {
                    continue;
                }
                $out[] = [$prop->getName() . '->' . $method->getName(), $method];
            }
        }

        return $out;
    }

    public function testEveryMethodEndsWithParamsThenOpts(): void
    {
        $methods = $this->publicMethods();
        self::assertGreaterThan(80, count($methods));

        foreach ($methods as [$name, $method]) {
            $params = $method->getParameters();
            self::assertGreaterThanOrEqual(2, count($params), $name);
            $opts = $params[count($params) - 1];
            $body = $params[count($params) - 2];

            self::assertSame('opts', $opts->getName(), "{$name}: last parameter");
            self::assertSame('array', (string) $opts->getType(), "{$name}: \$opts type");
            self::assertTrue($opts->isDefaultValueAvailable() && $opts->getDefaultValue() === [], "{$name}: \$opts = []");

            self::assertSame('params', $body->getName(), "{$name}: second-to-last parameter");
            self::assertSame('array', (string) $body->getType(), "{$name}: \$params type");
            self::assertTrue($body->isDefaultValueAvailable() && $body->getDefaultValue() === [], "{$name}: \$params = []");

            foreach (array_slice($params, 0, -2) as $id) {
                self::assertSame('string', (string) $id->getType(), "{$name}: \${$id->getName()} is an id");
                self::assertMatchesRegularExpression('/^(id|[a-z]+Id)$/', $id->getName(), "{$name}: id parameter name");
                self::assertFalse($id->isOptional(), "{$name}: an id is required");
            }
        }
    }

    public function testEveryAutoPagingIteratorTakesWhatItsListTakes(): void
    {
        foreach ($this->publicMethods() as [$name, $method]) {
            if (!str_starts_with($method->getName(), 'autoPagingIterator')) {
                continue;
            }
            $class = $method->getDeclaringClass();
            $suffix = substr($method->getName(), strlen('autoPagingIterator'));
            $listName = match ($suffix) {
                '' => 'all',
                'Deliveries' => 'listDeliveries',
                'UsageRecords' => 'listUsageRecords',
                default => self::fail("{$name}: no list method known for it"),
            };
            self::assertTrue($class->hasMethod($listName), "{$name}: no {$listName}()");
            $list = $class->getMethod($listName);
            self::assertSame(
                array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $list->getParameters()),
                array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $method->getParameters()),
                "{$name} must take exactly what {$listName}() takes",
            );
            self::assertSame('Generator', (string) $method->getReturnType(), $name);
        }
    }

    public function testTheTransportIsNotPublic(): void
    {
        $prop = (new \ReflectionClass(BillKitClient::class))->getProperty('transport');

        self::assertTrue($prop->isPrivate());
    }
}
