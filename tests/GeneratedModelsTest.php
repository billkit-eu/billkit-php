<?php

declare(strict_types=1);

namespace BillKit\Tests;

use BillKit\Tools\ModelGenerator;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../tools/generate-models.php';

/**
 * src/Model/ is generated from the published OpenAPI document. Regenerating
 * it must change nothing; when it would, run `php tools/generate-models.php`
 * and commit the result.
 *
 * Skips when the spec is not beside the package, which is the case in the
 * published mirror: there the committed classes are the source.
 */
final class GeneratedModelsTest extends TestCase
{
    public function testRegeneratingProducesNoDiff(): void
    {
        if (!is_file(ModelGenerator::DEFAULT_SPEC)) {
            self::markTestSkipped('The OpenAPI document is only available inside the billstack monorepo.');
        }

        $stale = \BillKit\Tools\billkit_generate_models(ModelGenerator::DEFAULT_SPEC, check: true);

        self::assertSame(
            [],
            $stale,
            'src/Model is out of date with the OpenAPI document. Run `php tools/generate-models.php`.',
        );
    }

    public function testEveryGeneratedClassIsFinalExceptTheEventBase(): void
    {
        foreach (glob(__DIR__ . '/../src/Model/*.php') ?: [] as $path) {
            $class = 'BillKit\\Model\\' . basename($path, '.php');
            $reflection = new \ReflectionClass($class);
            if ($class === \BillKit\Model\Event::class) {
                self::assertFalse($reflection->isFinal());

                continue;
            }
            self::assertTrue($reflection->isFinal(), "{$class} must be final");
        }
    }

    public function testEveryWebhookEventTypeHasATypedClass(): void
    {
        foreach (\BillKit\EventType::all() as $type) {
            self::assertNotNull(\BillKit\Model\Registry::eventClass($type), "no typed event class for {$type}");
        }
    }
}
