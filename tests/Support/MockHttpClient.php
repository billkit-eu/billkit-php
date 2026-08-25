<?php

declare(strict_types=1);

namespace BillKit\Tests\Support;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Request-capturing PSR-18 client returning pre-staged responses in
 * order. The PHP analog of the Node SDK's ``makeMockFetch`` and the
 * Python SDK's ``respx``. Each test stages 1+ responses, runs the SDK
 * code, then asserts on the recorded {@see RequestInterface} log.
 */
final class MockHttpClient implements ClientInterface
{
    /** @var list<array{status: int, body: mixed, headers: array<string, string>, throw: ?\Throwable}> */
    private array $queue = [];

    /** @var list<RequestInterface> */
    public array $requests = [];

    private readonly Psr17Factory $psr17;

    public function __construct()
    {
        $this->psr17 = new Psr17Factory();
    }

    /**
     * Stage a response. ``$body`` is JSON-encoded unless already a string.
     *
     * @param array<string, mixed>|string|null $body
     * @param array<string, string>            $headers
     */
    public function stage(int $status, array|string|null $body = null, array $headers = []): self
    {
        $this->queue[] = ['status' => $status, 'body' => $body, 'headers' => $headers, 'throw' => null];

        return $this;
    }

    /** Stage a connection-level failure for the next request. */
    public function stageError(\Throwable $error): self
    {
        $this->queue[] = ['status' => 0, 'body' => null, 'headers' => [], 'throw' => $error];

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        if ($this->queue === []) {
            throw new \RuntimeException('MockHttpClient: no staged response for ' . $request->getUri());
        }
        $staged = array_shift($this->queue);
        if ($staged['throw'] !== null) {
            throw $staged['throw'];
        }

        $response = $this->psr17->createResponse($staged['status']);
        foreach ($staged['headers'] as $key => $value) {
            $response = $response->withHeader($key, $value);
        }
        if ($staged['body'] !== null) {
            $json = is_string($staged['body'])
                ? $staged['body']
                : (string) json_encode($staged['body']);
            $response = $response->withBody($this->psr17->createStream($json));
        }

        return $response;
    }

    public function lastRequest(): RequestInterface
    {
        if ($this->requests === []) {
            throw new \RuntimeException('MockHttpClient: no requests captured yet.');
        }

        return $this->requests[array_key_last($this->requests)];
    }
}
