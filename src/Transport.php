<?php

declare(strict_types=1);

namespace BillKit;

use BillKit\Exception\ApiConnectionException;
use BillKit\Exception\ApiException;
use BillKit\Exception\ApiTimeoutException;
use BillKit\Model\RequestMaps;
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
 * ext-json (and ext-curl for this default client), the same zero-dependency
 * stance the Stripe PHP SDK takes to avoid version conflicts in host apps.
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
 *
 * @internal Not part of the public API: reach the API through
 *           {@see BillKitClient}'s resources. Its signatures change without
 *           a major version.
 */
final class Transport
{
    public const DEFAULT_BASE_URL = 'https://api.billkit.eu';
    /** Seconds, like Guzzle and stripe-php. */
    public const DEFAULT_TIMEOUT = 30.0;

    /**
     * The longest timeout accepted, in seconds. 0.x took milliseconds, so a
     * 0.x call such as `new BillKitClient($key, null, 30000)` still type-checks
     * and would wait more than eight hours; anything above this is refused.
     */
    public const MAX_TIMEOUT = 600.0;

    /** Cap on the connection phase, in seconds; never exceeds the total timeout. */
    private const DEFAULT_CONNECT_TIMEOUT = 10.0;

    /**
     * How many storage redirects a document route may take. Both transports
     * read it so curl and PSR-18 cannot disagree about the limit. One hop is
     * what S3 actually does; the rest is headroom that still terminates.
     */
    private const MAX_REDIRECTS = 5;

    private readonly string $baseUrl;
    private readonly RetryPolicy $retryPolicy;
    private readonly LoggerInterface $logger;

    private ?\CurlHandle $curlHandle = null;

    public function __construct(
        private readonly string $apiKey,
        ?string $baseUrl = null,
        private readonly float $timeout = self::DEFAULT_TIMEOUT,
        ?RetryPolicy $retryPolicy = null,
        private readonly ?ClientInterface $httpClient = null,
        private readonly ?RequestFactoryInterface $requestFactory = null,
        private readonly ?StreamFactoryInterface $streamFactory = null,
        ?LoggerInterface $logger = null,
    ) {
        if (trim($apiKey) === '') {
            throw new \InvalidArgumentException('BillKit: an API key is required.');
        }
        self::assertTimeout($timeout, 'the timeout');
        $this->baseUrl = rtrim($baseUrl ?? self::DEFAULT_BASE_URL, '/');
        $this->retryPolicy = $retryPolicy ?? new RetryPolicy();
        $this->logger = $logger ?? new NullLogger();

        if ($httpClient !== null && ($requestFactory === null || $streamFactory === null)) {
            throw new \InvalidArgumentException(
                'BillKit: injecting a PSR-18 client also requires PSR-17 request + stream factories '
                . '(e.g. nyholm/psr7).',
            );
        }
        self::assertSendable($httpClient !== null, \extension_loaded('curl'));
    }

    /**
     * Refuse to build a transport that could never send a request.
     * `ext-curl` is only suggested by composer.json, because a caller who
     * injects a PSR-18 client does not need it; without either, every call
     * would fail at the first request with an undefined-function error, so
     * the constructor says what to install instead.
     *
     * @throws \RuntimeException
     */
    private static function assertSendable(bool $hasClient, bool $curlLoaded): void
    {
        if (!$hasClient && !$curlLoaded) {
            throw new \RuntimeException(
                'BillKit: no way to send a request. Install the PHP curl extension (ext-curl) for the '
                . 'bundled transport, or pass a PSR-18 $httpClient with PSR-17 $requestFactory and '
                . '$streamFactory (for example symfony/http-client with nyholm/psr7).',
            );
        }
    }

    /**
     * The JSON for a request body.
     *
     * An empty array must go out as ``{}`` wherever the API expects an
     * object, not ``[]``. PHP cannot tell an empty map from an empty list,
     * and ``json_encode([])`` picks the list, which the API rejects with
     * "Input should be a valid dictionary". Two places hit it: the body
     * itself, because no BillKit request body is ever a JSON array (a route
     * whose body is entirely optional, ``POST /v1/invoices/{id}/void`` with
     * no ``reason``), and a map field inside it (``['metadata' => []]``,
     * ``theme``, ``capabilities``), whose paths {@see RequestMaps} generates
     * from the OpenAPI document. ``JSON_FORCE_OBJECT`` is not the answer: it
     * would also rewrite the lists that are genuinely lists (``tiers``,
     * ``enabled_events``, ``payment_methods``).
     *
     * @param array<array-key, mixed> $body
     *
     * @throws \InvalidArgumentException when the body has no JSON form (a string
     *                                   that is not UTF-8, a resource, INF/NAN)
     */
    private static function encodeBody(string $method, string $path, array $body): string
    {
        $value = $body === [] ? new \stdClass() : RequestMaps::apply($method, $path, $body);
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $e) {
            // Raised before anything is sent, and caused by the caller's
            // value, so it is an argument error like every other one the SDK
            // refuses locally. The value itself is not quoted: it may be a
            // customer's name or address.
            throw new \InvalidArgumentException(
                sprintf(
                    'BillKit: the request body for %s %s cannot be encoded as JSON (%s). '
                    . 'Strings must be valid UTF-8, and numbers finite.',
                    $method,
                    $path,
                    $e->getMessage(),
                ),
                0,
                $e,
            );
        }
    }

    /**
     * Refuse a timeout that is not a number of seconds in (0, MAX_TIMEOUT].
     *
     * @throws \InvalidArgumentException
     */
    public static function assertTimeout(float $timeout, string $what): void
    {
        if (!($timeout > 0)) {
            throw new \InvalidArgumentException("BillKit: {$what} is a number of seconds greater than 0.");
        }
        if ($timeout > self::MAX_TIMEOUT) {
            throw new \InvalidArgumentException(sprintf(
                'BillKit: %s of %s seconds is over the %d-second maximum. Timeouts are seconds since 1.0 '
                . '(0.x took milliseconds): pass 30.0 for 30 seconds, not 30000.',
                $what,
                rtrim(rtrim(number_format($timeout, 3, '.', ''), '0'), '.'),
                (int) self::MAX_TIMEOUT,
            ));
        }
    }

    /**
     * Perform one API call, retrying transient failures per the policy.
     *
     * @param array<string, mixed>      $query
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed> Decoded JSON body (``[]`` when empty)
     */
    public function request(
        string $method,
        string $path,
        array $query = [],
        ?array $body = null,
        ?RequestOptions $opts = null,
    ): array {
        [$status, $headers, $raw] = $this->perform($method, $path, $query, $body, $opts ?? RequestOptions::parse([]));

        return $this->decodeSuccess($status, $headers, $raw);
    }

    /**
     * The retry loop. Returns the first 2xx response, or throws.
     *
     * @param array<string, mixed>      $query
     * @param array<string, mixed>|null $body
     *
     * @return array{0: int, 1: array<string, string>, 2: string} status, lowercased headers, body
     */
    private function perform(
        string $method,
        string $path,
        array $query,
        ?array $body,
        RequestOptions $opts,
        bool $followRedirects = false,
    ): array {
        $url = $this->buildUrl($path, $query);
        // Query-free; see logSafeUrl(). Never log $url itself.
        $loggedUrl = $this->logSafeUrl($path);
        // Generated ONCE, here, before the retry loop: every attempt of this
        // logical call carries the same key, which is what lets a retried
        // POST replay instead of charging twice.
        $idem = $this->autoIdempotencyKey($method, $opts->idempotencyKey);
        $headers = $this->buildHeaders($body !== null, $idem, $opts->headers);
        $timeoutMs = self::toMs($opts->timeout ?? $this->timeout);
        $policy = $opts->maxRetries === null
            ? $this->retryPolicy
            : $this->retryPolicy->withMaxAttempts($opts->maxRetries + 1);
        $encoded = $body !== null ? self::encodeBody($method, $path, $body) : null;

        $lastError = null;
        for ($attempt = 1; $attempt <= $policy->maxAttempts; $attempt++) {
            $this->logger->debug('BillKit request', [
                'method' => $method,
                'url' => $loggedUrl,
                'attempt' => $attempt,
                'max_attempts' => $policy->maxAttempts,
            ]);
            $startedAt = microtime(true);
            try {
                [$status, $respHeaders, $respBody] = $this->send($method, $url, $headers, $encoded, $timeoutMs, $followRedirects);
            } catch (ApiConnectionException $err) {
                $lastError = $err;
                if (!$policy->shouldRetry(null, $attempt)) {
                    throw $err;
                }
                $delay = $policy->backoffForMs($attempt + 1);
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
                return [$status, $respHeaders, $respBody];
            }

            $errorBody = $this->tryDecode($respBody);
            $retryAfterMs = $this->parseRetryAfterMs($respHeaders['retry-after'] ?? null);
            $error = ApiException::fromResponse(
                $status,
                $errorBody,
                $respHeaders,
                $retryAfterMs === null ? null : $retryAfterMs / 1000,
                $respBody,
            );

            // ``$error->errorCode`` is what separates a transient
            // ``409 idempotency_in_progress`` from every other (permanent)
            // 409; see {@see RetryPolicy::IN_PROGRESS_CODE}. The key on the
            // wire is unchanged across attempts, so the retry replays rather
            // than re-charges.
            if (!$policy->shouldRetry($status, $attempt, $retryAfterMs, $error->errorCode)) {
                throw $error;
            }
            $lastError = $error;
            $delay = ($status === 429 && $retryAfterMs !== null)
                ? $retryAfterMs
                : $policy->backoffForMs($attempt + 1);
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
     * Fetch a document route's raw bytes (the invoice + credit-note PDFs).
     *
     * Same retry budget, timeout and typed exceptions as {@see self::request()};
     * only the success-path decoding differs, which is why it shares that loop
     * rather than carrying a second copy of it.
     *
     * Redirects are followed for this call alone. S3-backed deployments answer
     * ``302`` to a presigned URL while blob-backed ones stream the bytes
     * inline, and following it is what makes the two storage adapters look
     * identical from here. The presigned URL carries its own credential and
     * must not be handed BillKit's API key, so ``CURLOPT_UNRESTRICTED_AUTH``
     * stays off: curl then drops the ``Authorization`` header on a cross-host
     * hop. That applies to a header set through ``CURLOPT_HTTPHEADER`` only
     * from **curl 7.58.0** (CVE-2018-1000007); every PHP 8.1+ runtime is well
     * past it. An injected PSR-18 client owns its own redirect policy, so the
     * PSR path resolves the hop itself instead (see {@see self::sendPsr()}),
     * and that is the path the test suite exercises, because the curl one is
     * curl's guarantee rather than ours.
     */
    public function requestBytes(string $method, string $path, ?RequestOptions $opts = null): string
    {
        return $this->perform($method, $path, [], null, $opts ?? RequestOptions::parse([]), followRedirects: true)[2];
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{0: int, 1: array<string, string>, 2: string} status, lowercased headers, body
     */
    private function send(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        int $timeoutMs,
        bool $followRedirects = false,
    ): array {
        return $this->httpClient !== null
            ? $this->sendPsr($method, $url, $headers, $body, $followRedirects)
            : $this->sendCurl($method, $url, $headers, $body, $timeoutMs, $followRedirects);
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{0: int, 1: array<string, string>, 2: string}
     */
    private function sendCurl(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        int $timeoutMs,
        bool $followRedirects = false,
    ): array {
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
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => min(self::toMs(self::DEFAULT_CONNECT_TIMEOUT), $timeoutMs),
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
            // Off by default; on only for the document routes. With
            // CURLOPT_UNRESTRICTED_AUTH left false, curl drops the
            // Authorization header on a hop to a different host, so a
            // presigned storage URL never sees BillKit's API key.
            CURLOPT_FOLLOWLOCATION => $followRedirects,
            CURLOPT_UNRESTRICTED_AUTH => false,
            CURLOPT_MAXREDIRS => self::MAX_REDIRECTS,
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
            $message = curl_error($ch) !== '' ? self::stripQueryStrings(curl_error($ch)) : 'curl request failed';
            if (curl_errno($ch) === CURLE_OPERATION_TIMEDOUT) {
                throw new ApiTimeoutException($message);
            }

            throw new ApiConnectionException($message);
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
    private function sendPsr(string $method, string $url, array $headers, ?string $body, bool $followRedirects = false): array
    {
        for ($hop = 0; ; $hop++) {
            [$status, $respHeaders, $responseBody] = $this->psrRoundTrip($method, $url, $headers, $body);

            if (!$followRedirects || $status < 300 || $status >= 400 || $hop >= self::MAX_REDIRECTS) {
                return [$status, $respHeaders, $responseBody];
            }
            $location = $respHeaders['location'] ?? '';
            // Absolute http(s) only. The storage adapters always answer with
            // one, and resolving a relative target against the API host would
            // point the hop back at us rather than at the object.
            if (preg_match('~^https?://~i', $location) !== 1) {
                return [$status, $respHeaders, $responseBody];
            }

            // A PSR-18 client's redirect policy is its own: some follow, some
            // do not, and the ones that do may replay the Authorization header
            // to the storage host. Resolving the hop here makes the behaviour
            // identical under every injected client, and the next request
            // deliberately carries no credential of ours: the presigned URL
            // has its own.
            unset($headers['Authorization']);
            $method = 'GET';
            $url = $location;
            $body = null;
        }
    }

    /**
     * One PSR-18 request/response, normalised to the shape send() returns.
     *
     * @param array<string, string> $headers
     *
     * @return array{0: int, 1: array<string, string>, 2: string}
     */
    private function psrRoundTrip(string $method, string $url, array $headers, ?string $body): array
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
            // Sanitised, not copied. Guzzle and friends build their
            // connection-failure messages by interpolating the full
            // request URI ("cURL error 6: ... for https://api.billkit.eu/
            // v1/customers?email=ada@example.com"), which puts back
            // exactly the query string the rest of this class is careful
            // to strip, and this message lands in the caller's exception
            // handler, their error tracker, and their logs.
            // The original exception rides along as ``getPrevious()``: the
            // message is sanitised and generic ("cURL error 6"), and the
            // client's own exception is the only thing carrying the rest.
            $message = self::stripQueryStrings($err->getMessage());
            // PSR-18 has no timeout exception of its own, so this reads the
            // client's message. Guzzle and Symfony both say "timed out", and
            // curl-backed clients add "cURL error 28".
            if (preg_match('~timed?[ -]?out|cURL error 28\b~i', $message) === 1) {
                throw new ApiTimeoutException($message, $err);
            }

            throw new ApiConnectionException($message, $err);
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
     * Truncate every `http(s)://…` URL in `$message` at its `?`.
     *
     * The counterpart to {@see self::logSafeUrl()} for text the SDK did
     * not write. HTTP-client libraries interpolate the full request URI
     * into their connection-failure messages, and BillKit list filters
     * routinely carry `?email=ada@example.com`, so re-emitting such a
     * message verbatim would leak customer PII into the caller's logs
     * through the one path that never passed through our own formatting.
     *
     * Only the query is dropped; scheme, host and path are what make the
     * message diagnosable, and none of them carry tenant data.
     */
    private static function stripQueryStrings(string $message): string
    {
        $sanitised = preg_replace(
            '~(https?://[^\s\'"<>]*?)\?[^\s\'"<>]*~i',
            '$1',
            $message,
        );

        // `preg_replace` returns null only on a PCRE failure. Falling back
        // to the raw message would defeat the point, so fall back to the
        // safe direction instead.
        return $sanitised ?? 'The HTTP client reported a connection failure.';
    }

    /**
     * @param array<string, mixed> $query
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
     * join a list with commas (the API's ``expand=a,b`` shape), and
     * URL-encode everything else.
     *
     * @param array<string, mixed> $query
     *
     * @throws \InvalidArgumentException for a value that has no query-string form
     */
    private function buildQuery(array $query): string
    {
        $parts = [];
        foreach ($query as $key => $value) {
            if ($value === null || $value === []) {
                continue;
            }
            if (is_array($value)) {
                $items = [];
                foreach ($value as $item) {
                    $items[] = self::queryScalar($key, $item);
                }
                $encoded = implode(',', $items);
            } else {
                $encoded = self::queryScalar($key, $value);
            }
            $parts[] = rawurlencode($key) . '=' . rawurlencode($encoded);
        }

        return implode('&', $parts);
    }

    /**
     * Seconds to curl's milliseconds, rounded up so a sub-millisecond value
     * never becomes 0, which curl reads as "no timeout".
     */
    private static function toMs(float $seconds): int
    {
        return max(1, (int) ceil($seconds * 1000));
    }

    private static function queryScalar(string $key, mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_string($value), is_int($value), is_float($value) => (string) $value,
            default => throw new \InvalidArgumentException(sprintf(
                "BillKit: the '%s' filter must be a string, number, bool or list of those, got %s.",
                $key,
                get_debug_type($value),
            )),
        };
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
        // RequestOptions has already turned a blank key into null; the trim
        // stays so this method is safe on its own.
        if ($supplied !== null && trim($supplied) !== '') {
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
     * Decode a 2xx body. An empty body is `[]`.
     *
     * A non-empty body that is not a JSON object or array is thrown as an
     * {@see ApiException} rather than handed back as `[]`: an empty model
     * would only move the failure to the caller's first property read. It is
     * nearly always something answering in the API's place, such as a
     * captive portal, a misrouted `baseUrl` or a proxy's HTML page; the
     * exception keeps the status, headers and the body as received.
     *
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     */
    private function decodeSuccess(int $status, array $headers, string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        $decoded = $this->tryDecode($raw);
        if ($decoded === null) {
            throw new ApiException(
                "BillKit API answered HTTP {$status} with a body that is not JSON. "
                . 'Check that baseUrl points at the BillKit API and that no proxy is answering in its place.',
                $status,
                requestId: $headers['x-request-id'] ?? $headers['request-id'] ?? null,
                headers: $headers,
                httpBody: $raw,
            );
        }

        return $decoded;
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
