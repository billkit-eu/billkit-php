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

$client = new BillKitClient('bk_test_...'); // or set BILLKIT_API_KEY

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
    apiKey: 'bk_test_...',
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
(other 4xx), `ServerException` (5xx), all extending `BillKitException`.

The class is chosen by **HTTP status**, not by the envelope's `type`. The
status is the field the API cannot get wrong. Requests that never reach a route
handler — an unmatched path, a method the route does not allow — are serialised
by the framework as `{"type": "api_error", "code": "unhandled"}` *with a 4xx
status*, so mapping on `type` would turn a plain 404 into a `ServerException`
and tell you BillKit had broken when the request was at fault. The envelope's
values are still on the thrown object as `errorType`, `errorCode` and `param`
if you want them.

`ApiConnectionException` is the one that never reached the API at all. Its
message is sanitised of query strings, so the underlying PSR-18 client's own
exception is kept as `getPrevious()` when you need the rest of the diagnosis.

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

`409 idempotency_in_progress` is retried too. It means an earlier request
carrying the same key is still in flight, which is the one 4xx where giving up
is the dangerous answer: that request may already have charged the customer,
and the obvious workaround — retry with a *fresh* key — is exactly what turns
one charge into two. The retry reuses the original key, so it either loses the
race again or replays the first call's result. Every other 409
(`idempotency_key_in_use`, a conflicting subscription state) fails immediately,
because retrying can only repeat it.

## Invoice and credit-note PDFs

```php
file_put_contents('invoice.pdf', $client->invoices->retrievePdf('inv_123'));
file_put_contents('credit-note.pdf', $client->creditNotes->retrievePdf('cn_123'));
```

Returns the raw bytes. S3-backed deployments answer with a redirect to a presigned URL, which the SDK follows under its own timeout and retry policy, so both storage adapters look the same from here — and the API key is never sent to the storage host, because the presigned URL carries its own credential. A deployment with PDF rendering disabled answers `501`, which surfaces as a `ServerException` with `errorCode == "rendering_pending"`; `retrieve()` still gives you the structured document to render yourself.

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
    apiKey: 'bk_test_...',
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
    apiKey: 'bk_test_...',
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
| `apiKeys` | create, retrieve, revoke, all, autoPagingIterator |
| `customers` | create, retrieve, update, delete, all (filter by `provisional`), autoPagingIterator, setVatNumber, purge |
| `products` | create, retrieve, update (archive with `['active' => false]`), all, autoPagingIterator |
| `prices` | create, retrieve, update (archive with `['active' => false]`), all, autoPagingIterator (filter by `product_id`) |
| `checkoutSessions` | create, retrieve |
| `oneShotPayments` | create, retrieve, all, autoPagingIterator (filter by `customer_id` / `status`) |
| `subscriptions` | retrieve, all, autoPagingIterator (filter by `customer_id`, `status`, `renewal_state`), cancel, pause, resume, reactivate, previewUpdate, update, reauthorizePaymentMethod, createUsageRecord, listUsageRecords, autoPagingIteratorUsageRecords, retrieveUsageSummary |
| `refunds` | create, retrieve, all, autoPagingIterator |
| `disputes` | retrieve, all, autoPagingIterator (filter by `status`, `payment_id`) |
| `webhookEndpoints` | create, retrieve, update (retire with `['status' => 'disabled']`), rotateSecret, all, autoPagingIterator, allDeliveries, autoPagingIteratorDeliveries, retrieveDelivery, redeliver, listEventTypes |
| `events` | retrieve, all, autoPagingIterator |
| `tenant` | capabilities, portalBranding, setPortalBranding, billingProfile, setBillingProfile, export, rotateProviderCredential |
| `coupons` | create, retrieve, update (withdraw with `['active' => false]`), validate, all, autoPagingIterator |
| `taxRates` | create, retrieve, update (retire with `['active' => false]`), all, autoPagingIterator |
| `invoices` | retrieve, retrievePdf, sendEmail, all, autoPagingIterator (filter by `customer_id`, `subscription_id`, `payment_id`, `status`), void |
| `creditNotes` | retrieve, retrievePdf, all, autoPagingIterator (filter by `invoice_id`, `customer_id`) |
| `auditLogs` | retrieve, all, autoPagingIterator (filter by `action` / `resource_type` / `resource_id` / `actor_id`) |
| `payments` | retrieve, retrieveProvider, all, autoPagingIterator (filter by `customer_id`) |
| `billingPortalSessions` | create, revoke |

### Expanding a relation

Six routes take `?expand=`, which attaches the related object next to the id a
response already carries, resolved for the whole page in one query. Spell it as
a list in the params array, or as the second argument on `retrieve()`:

```php
$page = $client->subscriptions->all(['expand' => ['customer', 'price']]);
$page['data'][0]['customer']['name'];

$sub = $client->subscriptions->retrieve($id, ['expand' => ['refund_eligibility']]);
$pay = $client->payments->retrieve($paymentId, ['expand' => ['refund_eligibility']]);
```

What each route accepts: `customers->all()` → `stats`; `products` → `prices`,
`stats`, `default_price`; `subscriptions` → `customer`, `price`, `refund_eligibility`;
`payments` → `customer`, `subscription`, `refund_eligibility` (retrieve only); `invoices` → `customer`;
`events->all()` → `customer`. An unknown relation is a `400` naming the ones
that work, and no other route accepts the parameter at all. An expanded
relation is a summary for rendering, not the whole resource, and one that has
since been purged expands to `null` rather than failing the page.

### When `null` means "clear this"

`null` values are stripped from a request body, with a few deliberate exceptions
where the API reads an explicit null as an erasure: `vat_number` on
`customers->setVatNumber()`, the address fields and `registration_number` on
`tenant->setBillingProfile()`, `default_price_id` and `description` on `products->update()`,
`name` on `customers->update()`, `description` on `webhookEndpoints->update()`,
`max_redemptions` and `redeem_by` on `coupons->update()`, and `display_name` on
`taxRates->update()`. Leave the key out to keep the stored value;
pass it as `null` to empty it. Your own `vat_id` is the exception inside that
call: it can be set once, and changing or clearing it afterwards is refused
(`vat_id_locked`) because support changes it.

```php
$client->customers->setVatNumber($id, ['vat_number' => null]);   // deregistered
$client->tenant->setBillingProfile(['country_code' => 'NL', 'address_line2' => null]);
$client->products->update($productId, ['default_price_id' => null]); // portal falls back to the newest price
$client->coupons->update($couponId, ['max_redemptions' => null]); // no redemption cap
```

### Retiring something, and deleting something

`delete()` exists on `customers` and `webhookEndpoints`, and it returns `['id' => ..., 'object' => ..., 'deleted' => true]` rather than the object: it has left the API, so there is nothing to hand back. A deleted endpoint takes its delivery rows with it, because those are readable only through the endpoint that owns them; the events stay in `$client->events`, which is the record of what you were sent.

The catalogue is retired through its update route instead, because it stays readable afterwards. Prices, products, tax rates and coupons take `['active' => false]`. Each of them has to survive: subscriptions renew against a price by id, an invoice records the VAT percentage a tax rate produced, and a redeemed coupon is part of what a customer was charged.

`['status' => 'disabled']` on a webhook endpoint is the other half of the pair, not a substitute for deleting. It stops delivery and keeps the endpoint, its secret and its history, and it can be turned back on.

A price never accepts `amount_cents`, `currency`, `interval` or `usage_type`, because those decide what a past charge *was* and subscriptions renew against a price by id. What it does accept is forward-looking: `active`, `metadata`, `payment_methods`, `refund_on_cancel`, the two refund windows, and `tax_behavior` — which moves one way only, settable while the price is still `unspecified` and never changed again.

```php
// Stop selling a price. It stays readable; customers on it keep renewing.
$archived = $client->prices->update($price['id'], ['active' => false]);

// Stop sending to an endpoint, without losing its signing secret.
$client->webhookEndpoints->update($endpoint['id'], ['status' => 'disabled']);

// Remove one entirely, along with its delivery rows.
$client->webhookEndpoints->delete($endpoint['id']); // => ['deleted' => true, ...]

// Remove a customer. Refused while they hold a subscription that can
// still charge them.
$client->customers->delete($customer['id']); // => ['deleted' => true, ...]
```

### Finding paused subscriptions

`status` and `renewal_state` answer different questions, and only one of them knows about pausing. `status` is where the subscription stands with its payments (`incomplete`, `trialing`, `active`, `past_due`, `canceled`). `renewal_state` is what happens when the current period ends (`auto_renew`, `paused`, `canceling`, `stopped`). Pausing sets `renewal_state` and leaves `status` at `active`, because the customer has paid for the period they are in:

```php
$paused = $client->subscriptions->all(['renewal_state' => 'paused']);

// Both filters take a comma-separated list, and carry onto every page:
foreach ($client->subscriptions->autoPagingIterator(100, ['status' => 'active,past_due']) as $sub) {
    // ...
}
```

`['status' => 'paused']` is not an accepted value and throws `InvalidRequestException`.

### Metered billing

A metered price charges for what was consumed. You report usage; at each period close BillKit invoices the period's total and charges the stored mandate.

There are three ways to price a unit, and a price uses exactly one of them.

```php
// 1. Whole minor units: 5 cents per unit.
$client->prices->create([
    'product_id' => $product['id'], 'amount_cents' => 5,
    'currency' => 'EUR', 'interval' => 'month', 'usage_type' => 'metered',
]);

// 2. Finer than a minor unit. '0.02' is 0.02 CENTS, i.e. EUR 0.0002 per unit:
//    the canonical per-API-call price, which no integer can express.
$client->prices->create([
    'product_id' => $product['id'], 'unit_amount_decimal' => '0.02',
    'currency' => 'EUR', 'interval' => 'month', 'usage_type' => 'metered',
]);

// 3. By bands. 'graduated' prices the units inside each band; 'volume' lets
//    the period total pick one band which then prices every unit. The same
//    table under the two modes is a different bill, so the mode is required.
$client->prices->create([
    'product_id' => $product['id'], 'currency' => 'EUR', 'interval' => 'month',
    'usage_type' => 'metered', 'billing_scheme' => 'tiered', 'tiers_mode' => 'graduated',
    'tiers' => [
        ['up_to' => 1000, 'unit_amount' => 1],               // first 1,000 at EUR 0.01
        ['up_to' => 'inf', 'unit_amount_decimal' => '0.5'],  // then EUR 0.005
    ],
]);
```

Send none of the three and the server refuses the price.

**`unit_amount_decimal` is a string, and a `float` throws.** PHP has no decimal type, so this is the client where the mistake is easiest to make: `'unit_amount_decimal' => 0.0002` is valid PHP, and `json_encode` would put a JSON *number* on the wire. A float raises `\InvalidArgumentException` before the request is sent, at the price level and inside every band. It is not coerced, because coercing would work for the rates that happen to round-trip through a double and silently mis-price the ones that do not. An `int` is accepted and stringified — an integer is exact, so only the float is a lie.

The rate is in **minor units**, so `'0.02'` is two hundredths of a cent, not two cents. The period's whole quantity is multiplied by the rate and rounded once, at the invoice, so a sub-cent rate loses nothing per record.

The last band must be `'up_to' => 'inf'`, because a bounded top band cannot price the usage above it. Write a free band as `'unit_amount' => 0`. Metered prices must use `'interval' => 'month'`, cannot have `trial_days`, and cannot set `refund_on_cancel`.

#### Reporting usage exactly once

```php
$client->subscriptions->createUsageRecord($sub['id'], [
    'quantity' => 1200,
    'identifier' => 'job-2026-09-19T10:00Z', // your id for what you are metering
]);
```

Two dedupe mechanisms, and they cover different failures. `idempotency_key` covers a retry of *that HTTP request*. `identifier` covers a retry of *your* call — a job runner replaying a task, a queue delivering twice, your code re-invoking after its own timeout — which reaches the API as a genuinely new request with a new key. A second report of the same identifier returns the first record unchanged rather than billing twice. If your reporting pipeline is at-least-once, `identifier` is the one that matters.

Records are immutable once written: they are the audit trail behind an invoice line, so there is no update or delete.

#### Knowing what the next invoice will be

```php
$summary = $client->subscriptions->retrieveUsageSummary($sub['id']);
$summary['pending_quantity'];     // 3
$summary['gross_cents'];          // 15
$summary['will_charge'];          // false
$summary['minimum_charge_cents']; // 100
```

Check `will_charge` before you promise a customer an amount. A period whose total is under `minimum_charge_cents` (EUR 1.00) is **not** charged, because the payment provider would refuse it. The usage is not lost: it stays pending and rolls into the next period, which is then billed for both. `net_cents` / `tax_cents` / `gross_cents` are computed through the same rate or tier table and the same VAT resolution the close itself uses, so this is a forecast of the real invoice rather than an estimate. `open_invoice_id` names an earlier cycle that is invoiced and still unsettled; while one is open, this period cannot be charged.

### Archiving a price

A price's amount, currency and interval are fixed at creation, so you stop selling one rather than editing it. `update()` takes every forward-looking field (`metadata`, `payment_methods`, `refund_on_cancel`, `refund_window_initial_days`, `refund_window_renewal_days`, `tax_behavior`), all optional; setting `refund_on_cancel` here covers the customers already on the price. The price keeps its id and stays readable, because subscriptions renew against it by id. Subscriptions already on it keep renewing at it; what stops is new business. Re-archiving is a no-op, so a retry is safe, and `['active' => true]` puts it back on sale unchanged.

```php
$archived = $client->prices->update($price['id'], ['active' => false]);
// $archived['active'] === false

$back = $client->prices->update($price['id'], ['active' => true]);
// $back['active'] === true, and the amount is exactly what it always was
```

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
