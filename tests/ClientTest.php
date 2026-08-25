<?php

declare(strict_types=1);

namespace BillKit\Tests;

use BillKit\BillKitClient;
use BillKit\Tests\Support\MockHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
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

    public function testFallsBackToEnvApiKey(): void
    {
        putenv('BILLKIT_API_KEY=sk_test_from_env');
        $http = (new MockHttpClient())->stage(200, ['id' => 'cus_1']);
        $psr17 = new Psr17Factory();
        $client = new BillKitClient(
            httpClient: $http,
            requestFactory: $psr17,
            streamFactory: $psr17,
        );

        $client->customers->retrieve('cus_1');

        self::assertSame('Bearer sk_test_from_env', $http->lastRequest()->getHeaderLine('Authorization'));
    }

    public function testInjectingPsrClientWithoutFactoriesThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BillKitClient(apiKey: 'sk_test_unit', httpClient: new MockHttpClient());
    }
}
