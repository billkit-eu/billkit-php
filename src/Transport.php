<?php

declare(strict_types=1);

namespace BillKit;

use BillKit\Exception\ApiConnectionException;
use BillKit\Exception\BillKitException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * HTTP transport with retry + error mapping.
 *
 * By default it uses a bundled ``curl`` client so ``composer require
 * billkit-eu/billkit-php`` pulls in **no** hard runtime dependencies beyond
 * ext-curl/ext-json, the same zero-dependency stance the Stripe PHP
 * SDK takes to avoid version conflicts in host apps.
 *
 * For full control (custom TLS, proxies, connection pooling, test
 * doubles) inject any PSR-18 client plus PSR-17 factories; the transport
 * then routes every request through them instead of curl. This injection
 * seam is the PHP analog of the Node SDK's injectable ``fetch`` and the
 * Python SDK's injectable ``httpx`` client.
 *
 * The bundled curl client keeps a single persistent handle for the
 * lifetime of the transport so repeated calls reuse the TLS connection
 * (HTTP keep-alive). That makes the transport **not** safe to share
 * across concurrent coroutines (Swoole/ReactPHP); inject a PSR-18
 * client if you need that.
 *
 * ## Logging
 *
 * A library has no business deciding where its host application's logs
 * go, so the transport defaults to a {@see NullLogger} and writes
 * nowhere. Inject any PSR-3 logger (Monolog, Laravel's ``Log`` channel,
 * Symfony's) to opt in:
 *
 * - **debug**: one record per HTTP attempt and one per response, with
 *   ``method``, ``url``, ``attempt``, ``status``, ``duration_ms`` and
 *   ``request_id`` (quote that id to BillKit support).
 * - **warning**: one record per retry, naming the reason and the delay
 *   before the next attempt.
 *
 * Deliberately **never** logged: the ``Authorization`` header or API key;
 * request and response **bodies** (they carry customer PII and billing
 * detail); and the **query string** (list filters carry values like
 * ``email=``); only the path is logged. The final failure isn't logged
 * either: it is thrown as a typed {@see BillKitException} carrying the
 * status, request id and retry-after, and logging it here as well would
 * hand the caller a duplicate they cannot suppress.
 */
final class Transport
{
    public const DEFAULT_BASE_URL = 'https://api.billkit.eu';
    public const DEFAULT_TIMEOUT_MS = 30_000;

    /** Cap on the connection phase; never exceeds the total timeout. */
    private const DEFAULT_CONNECT_TIMEOUT_MS = 10_000;

    private readonly string $baseUrl;
    private readonly RetryPolicy $retryPolicy;
    private readonly LoggerInterface $logger;

    private ?\CurlHandle $curlHandle = null;

    public function __construct(
        private readonly string $apiKey,
        ?string $baseUrl = null,
        private readonly int $timeoutMs = self::DEFAULT_TIMEOUT_MS,
        ?RetryPolicy $retryPolicy = null,
        private readonly ?ClientInterface $httpClient = null,
        private readonly ?RequestFactoryInterface $requestFactory = null,
        private readonly ?StreamFactoryInterface $streamFactory = null,
        ?LoggerInterface $logger = null,
    ) {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('BillKit: an API key is required.');
        }
        $this->baseUrl = rtrim($baseUrl ?? self::DEFAULT_BASE_URL, '/');
        $this->retryPolicy = $retryPolicy ?? new RetryPolicy();
        $this->logger = $logger ?? new NullLogger();

        if ($httpClient !== null && ($requestFactory === null || $streamFactory === null)) {
            throw new \InvalidArgumentException(
                'BillKit: injecting a PSR-18 client also requires PSR-17 request + stream factories '
                . '(e.g. nyholm/psr7 or php-http/discovery).',
            );
        }
        if ($httpClient === null && !\extension_loaded('curl')) {
            throw new \RuntimeException(
                'BillKit: the curl extension is required unless you inject a PSR-18 client.',
            );
        }
    }

    /**
     * Perform one API call, retrying transient failures per the policy.
     *
     * @param array<string, scalar|null>  $query
     * @param array<string, mixed>|null   $body
     * @param array<string, string>       $extraHeaders
     *
     * @return array<string, mixed> Decoded JSON body (``[]`` when empty)
     */
    public function request(
        string $method,
        string $path,
        array $query = [],
        ?array $body = null,
        ?string $idempotencyKey = null,
        array $extraHeaders = [],
    ): array {
        $url = $this->buildUrl($path, $query);
        // Query-free; see logSafeUrl(). Never log $url itself.
        $loggedUrl = $this->logSafeUrl($path);
        $idem = $this->autoIdempotencyKey($method, $idempotencyKey);
        $headers = $this->buildHeaders($body !== null, $idem, $extraHeaders);
        $encoded = $body !== null
            ? json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : null;

        $lastError = null;
        for ($attempt = 1; $attempt <= $this->retryPolicy->maxAttempts; $attempt++) {
            $this->logger->debug('BillKit request', [
                'method' => $method,
                'url' => $loggedUrl,
                'attempt' => $attempt,
                'max_attempts' => $this->retryPolicy->maxAttempts,
            ]);
            $startedAt = microtime(true);
            try {
                [$status, $respHeaders, $respBody] = $this->send($method, $url, $headers, $encoded);
            } catch (ApiConnectionException $err) {
                $lastError = $err;
                if (!$this->retryPolicy->shouldRetry(null, $attempt)) {
                    throw $err;
                }
                $delay = $this->retryPolicy->backoffForMs($attempt + 1);
                $this->logger->warning('BillKit retrying', [
                    'method' => $method,
                    'url' => $loggedUrl,
                    'reason' => 'connection error',
                    'attempt' => $attempt,
                    'delay_ms' => $delay,
                ]);
                $this->sleepMs($delay);
                continue;
            }

            $requestId = $respHeaders['x-request-id'] ?? $respHeaders['request-id'] ?? null;
            $this->logger->debug('BillKit response', [
                'method' => $method,
                'url' => $loggedUrl,
                'status' => $status,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'request_id' => $requestId,
            ]);

            if ($status >= 200 && $status < 300) {
                return $this->decodeSuccess($respBody);
            }

            $errorBody = $this->tryDecode($respBody);
            $retryAfterMs = $this->parseRetryAfterMs($respHeaders['retry-after'] ?? null);
            $error = BillKitException::fromResponse(
                $status,
                $errorBody,
                $requestId,
                $retryAfterMs === null ? null : $retryAfterMs / 1000,
            );

            if (!$this->retryPolicy->shouldRetry($status, $attempt, $retryAfterMs)) {
                throw $error;
            }
            $lastError = $error;
            $delay = ($status === 429 && $retryAfterMs !== null)
                ? $retryAfterMs
                : $this->retryPolicy->backoffForMs($attempt + 1);
            $this->logger->warning('BillKit retrying', [
                'method' => $method,
                'url' => $loggedUrl,
                'reason' => 'HTTP ' . $status,
                'attempt' => $attempt,
                'delay_ms' => $delay,
            ]);
            $this->sleepMs($delay);
        }

        throw $lastError ?? new ApiConnectionException('Retry budget exhausted with no recorded error.');
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{0: int, 1: array<string, string>, 2: string} status, lowercased headers, body
     */
    private function send(string $method, string $url, array $headers, ?string $body): array
    {
        return $this->httpClient !== null
            ? $this->sendPsr($method, $url, $headers, $body)
            : $this->sendCurl($method, $url, $headers, $body);
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{0: int, 1: array<string, string>, 2: string}
     */
    private function sendCurl(string $method, string $url, array $headers, ?string $body): array
    {
        // $url is baseUrl . path and $method is an HTTP verb, so both are
        // always non-empty. Assert it so PHPStan narrows to the
        // non-empty-string that curl_setopt's CURLOPT_URL/CURLOPT_CUSTOMREQUEST
        // stubs now require.
        assert($url !== '' && $method !== '');

        $ch = $this->curlHandle();
        $respHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => $this->timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => min(self::DEFAULT_CONNECT_TIMEOUT_MS, $this->timeoutMs),
            // With millisecond timeouts, disable curl's SIGALRM path so a
            // slow DNS lookup still honours the timeout (and stays safe in
            // threaded SAPIs).
            CURLOPT_NOSIGNAL => true,
            // Verify TLS explicitly. Never trust a php.ini that disabled it.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // Advertise every encoding curl was built with (gzip/deflate/br)
            // and transparently decompress, which is meaningful for large list pages.
            CURLOPT_ACCEPT_ENCODING => '',
            CURLOPT_HTTPHEADER => $this->headerLines($headers),
            CURLOPT_HEADERFUNCTION => static function ($_ch, string $line) use (&$respHeaders): int {
                $idx = strpos($line, ':');
                if ($idx !== false) {
                    $key = strtolower(trim(substr($line, 0, $idx)));
                    $respHeaders[$key] = trim(substr($line, $idx + 1));
                }

                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            throw new ApiConnectionException(
                curl_error($ch) !== '' ? curl_error($ch) : 'curl request failed',
            );
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        return [$status, $respHeaders, (string) $raw];
    }

    /**
     * Lazily create the shared curl handle, resetting it between calls so
     * options from a prior request never leak while the underlying
     * connection is kept alive for reuse.
     */
    private function curlHandle(): \CurlHandle
    {
        if ($this->curlHandle === null) {
            $handle = curl_init();
            if ($handle === false) {
                throw new ApiConnectionException('BillKit: failed to initialise curl.');
            }
            $this->curlHandle = $handle;
        } else {
            curl_reset($this->curlHandle);
        }

        return $this->curlHandle;
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{0: int, 1: array<string, string>, 2: string}
     */
    private function sendPsr(string $method, string $url, array $headers, ?string $body): array
    {
        $client = $this->httpClient;
        $requestFactory = $this->requestFactory;
        $streamFactory = $this->streamFactory;
        if ($client === null || $requestFactory === null || $streamFactory === null) {
            // Unreachable: the constructor guarantees the PSR trio is
            // all-or-nothing and send() only routes here when a client is set.
            throw new \LogicException('BillKit: PSR transport invoked without a client and factories.');
        }

        $request = $requestFactory->createRequest($method, $url);
        foreach ($headers as $key => $value) {
            $request = $request->withHeader($key, $value);
        }
        if ($body !== null) {
            $request = $request->withBody($streamFactory->createStream($body));
        }

        try {
            $response = $client->sendRequest($request);
        } catch (ClientExceptionInterface $err) {
            throw new ApiConnectionException($err->getMessage());
        }

        $respHeaders = [];
        foreach ($response->getHeaders() as $key => $values) {
            $respHeaders[strtolower($key)] = implode(', ', $values);
        }

        return [$response->getStatusCode(), $respHeaders, (string) $response->getBody()];
    }

    /**
     * The URL with the **query string stripped**, for logging only.
     *
     * Never log what {@see self::buildUrl()} returns: list filters
     * routinely carry ``?email=ada@example.com``, and copying customer
     * PII into the caller's log sink is exactly what this SDK must not
     * do. Keeping the two builders separate makes that a visible choice
     * rather than an accident waiting for someone to "simplify" it.
     */
    private function logSafeUrl(string $path): string
    {
        $normalised = str_starts_with($path, '/') ? $path : '/' . $path;

        return $this->baseUrl . $normalised;
    }

    /**
     * @param array<string, scalar|null> $query
     */
    private function buildUrl(string $path, array $query): string
    {
        $normalised = str_starts_with($path, '/') ? $path : '/' . $path;
        $url = $this->baseUrl . $normalised;
        $qs = $this->buildQuery($query);

        return $qs === '' ? $url : $url . '?' . $qs;
    }

    /**
     * Serialise query params the same way the Node SDK does: skip
     * ``null``, stringify booleans as ``true``/``false`` (not ``1``/``0``),
     * URL-encode everything else.
     *
     * @param array<string, scalar|null> $query
     */
    private function buildQuery(array $query): string
    {
        $parts = [];
        foreach ($query as $key => $value) {
            if ($value === null) {
                continue;
            }
            $encoded = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            $parts[] = rawurlencode($key) . '=' . rawurlencode($encoded);
        }

        return implode('&', $parts);
    }

    /**
     * @param array<string, string> $extra
     *
     * @return array<string, string>
     */
    private function buildHeaders(bool $hasBody, ?string $idempotencyKey, array $extra): array
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'User-Agent' => Version::userAgent(),
            'Accept' => 'application/json',
        ];
        if ($hasBody) {
            $headers['Content-Type'] = 'application/json';
        }
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }
        foreach ($extra as $key => $value) {
            $headers[$key] = $value;
        }

        return $headers;
    }

    /**
     * @param array<string, string> $headers
     *
     * @return list<string>
     */
    private function headerLines(array $headers): array
    {
        $lines = [];
        foreach ($headers as $key => $value) {
            $lines[] = $key . ': ' . $value;
        }

        return $lines;
    }

    private function autoIdempotencyKey(string $method, ?string $supplied): ?string
    {
        if ($method === 'GET') {
            return null;
        }
        if ($supplied !== null) {
            return $supplied;
        }

        return 'sdk-' . $this->uuidV4();
    }

    private function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40); // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80); // variant 10
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    private function parseRetryAfterMs(?string $header): ?float
    {
        if ($header === null || $header === '') {
            return null;
        }
        if (is_numeric($header)) {
            $seconds = (float) $header;

            return $seconds >= 0 ? $seconds * 1000 : null;
        }
        $retryAt = strtotime($header);
        if ($retryAt === false) {
            return null;
        }

        return max(0.0, ($retryAt - time()) * 1000);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeSuccess(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        $decoded = $this->tryDecode($raw);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function tryDecode(string $raw): ?array
    {
        if ($raw === '') {
            return null;
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($decoded)) {
            return null;
        }

        // Normalise to string keys: top-level API responses are JSON
        // objects, and this makes the ``array<string, mixed>`` contract
        // honest even if a numeric-keyed body ever comes back.
        $normalised = [];
        foreach ($decoded as $key => $value) {
            $normalised[(string) $key] = $value;
        }

        return $normalised;
    }

    private function sleepMs(float $ms): void
    {
        if ($ms <= 0) {
            return;
        }
        usleep((int) round($ms * 1000));
    }
}
