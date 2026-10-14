<?php

declare(strict_types=1);

namespace BillKit\Tests;

use BillKit\BillKitClient;
use BillKit\Tests\Support\MockHttpClient;
use BillKit\Transport;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('BILLKIT_API_KEY');
    }

    public function testThrowsWhenNoApiKeyAndNoEnv(): void
    {
        putenv('BILLKIT_API_KEY');
        $this->expectException(\InvalidArgumentException::class);
        new BillKitClient();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blankKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'spaces' => ['   '];
        yield 'newline' => ["\n"];
        yield 'tab' => ["\t "];
    }

    #[DataProvider('blankKeys')]
    public function testABlankApiKeyIsRefusedEvenWithTheEnvSet(string $key): void
    {
        // An explicit blank key is the caller's bug, never "use the env":
        // falling back would run with whatever key the environment holds.
        putenv('BILLKIT_API_KEY=bk_test_from_env');
        try {
            new BillKitClient($key);
            self::fail('a blank API key was accepted');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('$apiKey is empty', $e->getMessage());
        }
    }

    #[DataProvider('blankKeys')]
    public function testABlankApiKeyIsRefusedByTheTransport(string $key): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Transport($key);
    }

    public function testABlankEnvKeyCountsAsUnset(): void
    {
        putenv('BILLKIT_API_KEY=   ');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('missing API key');
        new BillKitClient();
    }

    public function testFallsBackToEnvApiKey(): void
    {
        putenv('BILLKIT_API_KEY=bk_test_from_env');
        $http = (new MockHttpClient())->stage(200, ['id' => 'cus_1']);
        $psr17 = new Psr17Factory();
        $client = new BillKitClient(
            httpClient: $http,
            requestFactory: $psr17,
            streamFactory: $psr17,
        );

        $client->customers->retrieve('cus_1');

        self::assertSame('Bearer bk_test_from_env', $http->lastRequest()->getHeaderLine('Authorization'));
    }

    public function testAPositionalMillisecondTimeoutFrom0xIsRefused(): void
    {
        // 0.x: new BillKitClient($key, null, 30000) meant 30 seconds. In 1.0
        // the third argument is seconds, so the same call would wait 8.3 hours.
        try {
            new BillKitClient('bk_test_unit', null, 30000);
            self::fail('a 30000-second timeout was accepted');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('30000 seconds is over the 600-second maximum', $e->getMessage());
            self::assertStringContainsString('0.x took milliseconds', $e->getMessage());
        }

        // The maximum itself is fine.
        new BillKitClient('bk_test_unit', null, 600);
        self::assertSame(600.0, \BillKit\Transport::MAX_TIMEOUT);
    }

    public function testInjectingPsrClientWithoutFactoriesThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BillKitClient(apiKey: 'bk_test_unit', httpClient: new MockHttpClient());
    }

    public function testNeitherCurlNorAPsrClientIsRefusedAtConstruction(): void
    {
        // ext-curl is only suggested by composer.json, so a host can install
        // the SDK without it. Construction must then say what is missing
        // rather than fail at the first request. The extension cannot be
        // unloaded in-process, so the decision is called directly.
        $assert = new \ReflectionMethod(\BillKit\Transport::class, 'assertSendable');

        $assert->invoke(null, true, false);   // injected client, no curl
        $assert->invoke(null, false, true);   // bundled curl
        try {
            $assert->invoke(null, false, false);
            self::fail('expected a transport with no way to send to be refused');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('ext-curl', $e->getMessage());
            self::assertStringContainsString('PSR-18', $e->getMessage());
        }
    }

    public function testComposerOnlySuggestsCurlAndNamesNoUnusedPackage(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__ . '/../composer.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayNotHasKey('ext-curl', $composer['require']);
        self::assertArrayHasKey('ext-curl', $composer['suggest']);
        // Nothing in src/ uses php-http/discovery; suggesting it promised
        // auto-wiring the SDK does not do.
        self::assertArrayNotHasKey('php-http/discovery', $composer['suggest']);
    }
}
