# BillKit PHP SDK

Official PHP SDK for [BillKit](https://billkit.eu), a Stripe-Billing-shape,
multi-tenant SaaS billing API running on Mollie.

- **PHP 8.1+**, PSR-4, no hard runtime dependencies beyond `ext-curl` / `ext-json`.
- Full resource coverage, typed exception hierarchy, automatic retries with
  idempotency, cursor auto-pagination, and webhook signature verification.
- Bring-your-own PSR-18 HTTP client (Guzzle, Symfony HttpClient, ...) when you
  need custom transport behaviour.

## Install

```bash
composer require billkit-eu/billkit-php
```

## Quick start

```php
use BillKit\BillKitClient;

$client = new BillKitClient('sk_test_...'); // or set BILLKIT_API_KEY

$customer = $client->customers->create([
    'email' => 'ada@example.com',
    'name'  => 'Ada Lovelace',
]);

$product = $client->products->create(['name' => 'Pro']);

$price = $client->prices->create([
    'product_id'   => $product['id'],
    'amount_cents' => 999,
    'currency'     => 'EUR',
    'interval'     => 'month',
]);

$session = $client->checkoutSessions->create([
    'customer_id' => $customer['id'],
    'price_id'    => $price['id'],
    'success_url' => 'https://app.example.com/done',
    'cancel_url'  => 'https://app.example.com/pricing',
]);
```

Every method returns the decoded JSON body as a plain associative `array`.
The SDK deliberately ships no model classes so responses forward through your
own data layer unchanged.

## One-shot payments

Charge a customer a single time without creating a mandate: no subscription,
no renewal. Create the payment, redirect the shopper to `redirect_url`, then
(optionally) refund it later. `refund_window_days` sets how long the charge
stays refundable: `0` disables refunds, the default is `30`, the max is `365`.

```php
$payment = $client->oneShotPayments->create([
    'customer_id'  => $customer['id'],
    'amount_cents' => 1999,
    'currency'     => 'EUR',
    'method'       => 'ideal',
    'success_url'  => 'https://app.example.com/done',
    'cancel_url'   => 'https://app.example.com/cart',
]);

header('Location: ' . $payment['redirect_url']); // send the shopper to pay

// The payment settles via the one_shot_payment.succeeded / .failed webhooks.
// Refund a settled one-shot payment (omit amount_cents for a full refund):
$client->refunds->create(['one_shot_payment_id' => $payment['id']]);
// ...or refund part of it. A charge can carry several partials:
$client->refunds->create(['one_shot_payment_id' => $payment['id'], 'amount_cents' => 500]);
```

## Configuration

```php
use BillKit\BillKitClient;
use BillKit\RetryPolicy;

$client = new BillKitClient(
    apiKey: 'sk_test_...',
    baseUrl: 'https://api.billkit.eu',          // override for self-hosted
    timeoutMs: 30_000,
    retryPolicy: new RetryPolicy(maxAttempts: 4),
    logger: $psrLogger,                          // opt-in; omitted = silent
);
```

The API key resolves from the constructor argument, falling back to the
`BILLKIT_API_KEY` environment variable.

## Auto-pagination

List endpoints expose `all()` (one page) and `autoPagingIterator()` (a
`Generator` that walks every page via the `has_more` + `starting_after`
cursor protocol):

```php
foreach ($client->customers->autoPagingIterator() as $customer) {
    echo $customer['id'], "\n";
}

// Server-side filters are first-class where the API supports them:
foreach ($client->events->autoPagingIterator(type: 'customer.created') as $event) {
    // ...
}
```

## Error handling

Non-2xx responses raise a typed subclass of `BillKit\Exception\BillKitException`,
so you catch the case you care about instead of branching on status codes:

```php
use BillKit\Exception\ResourceMissingException;
use BillKit\Exception\RateLimitException;
use BillKit\Exception\BillKitException;

try {
    $client->customers->retrieve('cus_missing');
} catch (ResourceMissingException $e) {
    // 404
} catch (RateLimitException $e) {
    sleep((int) ceil($e->retryAfter ?? 1));
} catch (BillKitException $e) {
    error_log($e->errorType . ': ' . $e->getMessage() . ' (request ' . $e->requestId . ')');
}
```

Hierarchy: `ApiConnectionException`, `AuthenticationException` (401),
`PermissionException` (403), `ResourceMissingException` (404),
`ConflictException` (409), `RateLimitException` (429), `InvalidRequestException`
(4xx), `ServerException` (5xx), all extending `BillKitException`.

## Retries & idempotency

Transient failures (connection errors, 5xx, and 429 with a short `Retry-After`)
are retried with jittered exponential backoff. Every mutating call is sent with
an auto-generated `Idempotency-Key`, so a retried request never double-charges.
Supply your own to coalesce retries across process restarts:

```php
$client->refunds->create([
    'payment_id'      => 'pay_1',
    'idempotency_key' => 'refund-order-4711',
]);
```

## Webhooks

Verify the `BillKit-Signature` header before trusting a webhook body:

```php
use BillKit\Webhooks;
use BillKit\Exception\WebhookVerificationException;

try {
    $event = Webhooks::verifySignature(
        payload: file_get_contents('php://input'),
        signatureHeader: $_SERVER['HTTP_BILLKIT_SIGNATURE'] ?? null,
        secret: getenv('BILLKIT_WEBHOOK_SECRET'),
    );
} catch (WebhookVerificationException $e) {
    http_response_code(400);
    exit;
}

// $event is the decoded, verified payload.
```

## Custom HTTP client (PSR-18)

By default the SDK uses a bundled curl transport. To route requests through
your own PSR-18 client (for custom TLS, proxies, or connection pooling),
inject it alongside PSR-17 factories:

```php
use BillKit\BillKitClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;

$factory = new HttpFactory();
$client = new BillKitClient(
    apiKey: 'sk_test_...',
    httpClient: new GuzzleClient(),
    requestFactory: $factory,
    streamFactory: $factory,
);
```

## Logging (PSR-3)

The SDK is **silent by default**: it defaults to a `NullLogger` and writes nowhere, so it can't take over your application's logging. Inject any PSR-3 logger to opt in:

```php
use BillKit\BillKitClient;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

$log = new Logger('billkit');
$log->pushHandler(new StreamHandler('php://stderr', Logger::DEBUG));

$client = new BillKitClient(
    apiKey: 'sk_test_...',
    logger: $log,
);
```

```
billkit.DEBUG: BillKit request {"method":"POST","url":"https://api.billkit.eu/v1/customers","attempt":1,"max_attempts":3}
billkit.DEBUG: BillKit response {"method":"POST","url":".../v1/customers","status":503,"duration_ms":84,"request_id":"req_9f2a"}
billkit.WARNING: BillKit retrying {"method":"POST","url":".../v1/customers","reason":"HTTP 503","attempt":1,"delay_ms":500}
billkit.DEBUG: BillKit response {"method":"POST","url":".../v1/customers","status":200,"duration_ms":91,"request_id":"req_9f2b"}
```

- **debug**: one record per attempt, one per response (`status`, `duration_ms`, `request_id`; quote that id to support).
- **warning**: one record per retry, with the reason and the delay before the next attempt.

**Never logged:** your API key or the `Authorization` header; request and response **bodies** (they carry customer PII); the **query string** (list filters carry values like `email=`); only the path is logged. The final failure isn't logged either: it's thrown as a typed `BillKitException` carrying the status, request id and retry-after, and logging it here too would hand you a duplicate you can't suppress.

Using Laravel? The [`billkit-eu/billkit-laravel`](../laravel) package wires a log channel for you via `config/billkit.php`.

## API surface

Every resource is a property on the client. List resources expose `all()` (one
page) and `autoPagingIterator()` (walk all pages).

| `$client->...` | Methods |
|--------------|---------|
| `customers` | create, retrieve, update, delete, all, autoPagingIterator, setVatNumber, purge |
| `products` | create, retrieve, update, delete, all, autoPagingIterator |
| `prices` | create, retrieve, all, autoPagingIterator |
| `checkoutSessions` | create, retrieve |
| `oneShotPayments` | create, retrieve |
| `subscriptions` | retrieve, all, autoPagingIterator, cancel, pause, resume, reactivate, previewUpdate, update, reauthorizePaymentMethod |
| `refunds` | create, retrieve, all, autoPagingIterator |
| `webhookEndpoints` | create, retrieve, update, delete, rotateSecret, all, autoPagingIterator, allDeliveries, autoPagingIteratorDeliveries, retrieveDelivery, redeliver |
| `events` | retrieve, all, autoPagingIterator |
| `tenant` | capabilities, portalBranding, setPortalBranding, rotateProviderCredential |
| `coupons` | create, retrieve, update, delete, validate, all, autoPagingIterator |
| `taxRates` | create, retrieve, update, delete, all, autoPagingIterator |
| `invoices` | retrieve, all, autoPagingIterator |
| `auditLogs` | retrieve, all, autoPagingIterator |
| `payments` | retrieve, all, autoPagingIterator |
| `billingPortalSessions` | create, revoke |

Plus `BillKit\Webhooks::verifySignature(...)` (static) for inbound webhooks.

## Development

```bash
composer install
composer test      # PHPUnit
composer analyse   # PHPStan (level max)
composer cs        # php-cs-fixer (apply)
composer cs:check  # php-cs-fixer (dry-run)
```

## License

Apache-2.0
