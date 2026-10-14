# BillKit PHP SDK

Official PHP SDK for [BillKit](https://billkit.eu), a Stripe-Billing-shape,
multi-tenant SaaS billing API running on Mollie.

- **PHP 8.1+**, PSR-4, no hard runtime dependencies beyond `ext-json`. The bundled transport needs `ext-curl` (suggested, not required); inject a PSR-18 client and it is not needed.
- Typed response objects generated from the API's OpenAPI document, with array
  access kept for the 0.x style.
- A typed exception hierarchy, automatic retries with idempotency, cursor
  auto-pagination, typed webhook events and signature verification.
- Bring-your-own PSR-18 HTTP client (Guzzle, Symfony HttpClient, ...) when you
  need custom transport behaviour.

Upgrading from 0.x? 1.0 is a deliberate break; [CHANGELOG.md](CHANGELOG.md)
has the full "Upgrading from 0.x" list with before and after for each change.

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
    'product_id'   => $product->id,
    'amount_cents' => 999,
    'currency'     => 'EUR',
    'interval'     => 'month',
]);

$session = $client->checkoutSessions->create([
    'customer_id' => $customer->id,
    'price_id'    => $price->id,
    'success_url' => 'https://app.example.com/done',
    'cancel_url'  => 'https://app.example.com/pricing',
]);

header('Location: ' . $session->url, true, 303);
```

## Responses are typed objects

Every method returns an object generated from the API's schema:
`customers->create()` returns a `BillKit\Model\Customer`, `subscriptions->retrieve()`
a `BillKit\Model\Subscription`, and so on. Known fields are typed, read-only
public properties, so your IDE and PHPStan know `$subscription->status` is a
`?string` and `$subscription->canceled_at` an `?int`. Every property is
nullable; see **Nullable, always** below.

Every object is also read-only array access over the raw response. **Both
access styles are permanent**, and you can mix them:

```php
$sub = $client->subscriptions->retrieve('sub_123', ['expand' => ['customer']]);

$sub->status;                 // 'active'
$sub['status'];               // 'active', the 0.x style
$sub->customer?->email;       // nested objects are typed too
$sub['customer']['email'];

$sub->toArray();              // a plain array, recursively: exactly what 0.x returned
json_encode($sub);            // the JSON the API sent (an empty metadata stays {})
$sub->has('canceled_at');     // true even when the value is null
```

- **Immutable.** Writing through array access, or to a field the class does not
  declare, throws `\LogicException`; assigning a declared property is PHP's own
  readonly `\Error` ("Cannot modify readonly property"). Copy with `toArray()`
  when you need to change something.
- **Forward compatible.** The API adds fields and enum values without a new SDK
  release. A field this version does not know is kept and reachable as
  `$obj['new_field']` (or `$obj->new_field`); decoding never throws on it.
- **Open enums.** Enum fields are plain strings, with the values this version
  knows as class constants: `Subscription::STATUS_PAST_DUE`,
  `Coupon::DISCOUNT_TYPE_PERCENT`, `Price::INTERVAL_YEAR`. A value added later
  is just a string you have not seen; compare against the constants and treat
  anything else as "unknown", never as an error.
- **Nullable, always.** Every typed property is `?type` and always set, so
  reading one never throws: a field the payload lacks, or carries with the
  wrong type, is `null`, and the raw value is still in `$obj['field']`. That
  includes fields the API always sends today, `id` and `object` among them,
  because not every payload is a live response. An event's `data` is the
  object as it was recorded; `events->retrieve()` and webhook redeliveries
  serve that snapshot unchanged, and one recorded before a field existed does
  not have it. Check for `null` where a missing value matters.
- **JSON.** `json_encode($obj)` writes an empty JSON-object field the class
  declares (`metadata`, `previous_attributes`) as `{}`. `toArray()` cannot,
  since PHP has one array type, so an empty `metadata` is `[]` there, as is an
  empty object in a field this SDK version does not know.
- `BillKit\BillKitObject::constructFrom($array)` (and the same on every model
  class) builds an object from a decoded array, for fixtures in your own tests.

`retrievePdf()` and `tenant->export()` return raw bytes as a `string`.

## Calling convention and request options

Every resource method has one of two shapes, and always ends with `$opts`:

```php
$client->refunds->create(array $params = [], array $opts = []);
$client->subscriptions->cancel(string $id, array $params = [], array $opts = []);
```

`$params` is what goes to the API, under the API's own snake_case names: the
JSON body of a POST, the query string of a GET. `$opts` is how the SDK sends it:

| Option | Meaning |
|---|---|
| `idempotency_key` | Sent as `Idempotency-Key`. Blank counts as unset, and a key is generated. |
| `timeout` | This call's timeout **in seconds** (int or float, as in Guzzle). Bundled curl transport only; an injected PSR-18 client keeps its own timeouts. |
| `max_retries` | Retries after the first attempt, for this call; `0` sends it once. |
| `headers` | Extra request headers, `name => value`. `Authorization`, `Idempotency-Key`, `Content-Type` and `User-Agent` belong to the SDK and are refused. |

```php
$client->refunds->create(
    ['payment_id' => 'pay_123', 'amount_cents' => 500],
    ['idempotency_key' => 'refund-order-4711', 'timeout' => 10],
);
```

An unknown option, or `idempotency_key` inside `$params`, throws
`\InvalidArgumentException` before any request is made: a misspelt option must
not send a payment request without the key you meant to protect it with.

`null` values are stripped from a request body; `false`, `0` and `''` are kept.
The few keys where `null` means "clear this" are listed under
[When `null` means "clear this"](#when-null-means-clear-this).

## Configuration

```php
use BillKit\BillKitClient;
use BillKit\RetryPolicy;

$client = new BillKitClient(
    apiKey: 'bk_test_...',
    baseUrl: 'https://api.billkit.eu',          // override for self-hosted
    timeout: 30.0,                               // seconds; at most 10 go on connecting
    retryPolicy: new RetryPolicy(maxAttempts: 4),
    logger: $psrLogger,                          // opt-in; omitted = silent
);
```

The API key resolves from the constructor argument, falling back to the
`BILLKIT_API_KEY` environment variable when the argument is `null`; with
neither, the constructor throws `\InvalidArgumentException`. An empty or
whitespace-only key passed explicitly throws too, rather than falling back to
the environment. A `bk_test_` key reads and writes test-mode data
and a `bk_live_` key live data; there is no mode switch on the client.

## Pagination

`all()` returns one page as a `BillKit\Collection` of typed objects.
`autoPagingIterator()` is a `Generator` over every page, following the
`starting_after` cursor, and takes exactly the same `$params` as `all()`:

```php
$page = $client->customers->all(['limit' => 10]);
$page->data;        // list<Customer>, this page only
$page->has_more;    // more beyond it?
foreach ($page as $customer) { /* this page */ }

foreach ($client->events->autoPagingIterator(['type' => 'customer.created']) as $event) {
    // every matching event, page after page
}

// Or continue from a page you already have, with its filters and options:
foreach ($page->autoPagingIterator() as $customer) { /* ... */ }
```

Filters carry onto every page request, so a filtered walk narrows server-side.
`limit` sets the page size. Paging is forward-only. A field of the page this
version does not declare is reachable as `$page->field`, as on any object.

A page can be serialized, so `Cache::remember('plans', 600, fn () => $client->prices->all())`
works, but the restored copy has lost its connection to the client: its
`autoPagingIterator()` yields the items it holds and then throws
`\LogicException` when `has_more` is true, rather than ending as if the list
were complete. The same goes for a page built with `Collection::constructFrom()`.
Cache the items you need, or run the list call again to continue.

## Errors

Every error from a request extends `BillKit\Exception\BillKitException`
(itself a `\RuntimeException`); an argument mistake caught before anything is
sent throws `\InvalidArgumentException` instead (see below).

| Exception | When |
|---|---|
| `ApiException` | Any HTTP error response, 4xx and 5xx alike, and the parent of the classes below. Thrown as itself for a 2xx whose body is not JSON |
| `InvalidRequestException` | 400 and any other 4xx without its own class |
| `AuthenticationException` | 401: missing, malformed or revoked key |
| `PaymentException` | 402: `errorCode` `payment_refused` (the provider declined a charge or refund; the message has its reason) or `tenant_suspended` (the account is suspended). Not retried |
| `PermissionDeniedException` | 403: the key's scopes do not cover the route |
| `NotFoundException` | 404, including an id that belongs to another account |
| `ConflictException` | 409: a state clash, or an idempotency key reused with a different body |
| `RateLimitException` | 429; carries `retryAfter` in seconds |
| `ServerException` | 5xx |
| `ApiConnectionException` | No response at all: DNS, TLS, refused or reset connection |
| `ApiTimeoutException` | A timeout; a subclass of `ApiConnectionException` |
| `WebhookVerificationException` | A webhook that failed signature verification |

```php
use BillKit\Exception\ApiException;
use BillKit\Exception\NotFoundException;
use BillKit\Exception\RateLimitException;

try {
    $client->customers->retrieve('cus_missing');
} catch (NotFoundException $e) {
    // 404
} catch (RateLimitException $e) {
    sleep((int) ceil($e->retryAfter ?? 1));
} catch (ApiException $e) {
    error_log("{$e->statusCode} {$e->errorType}/{$e->errorCode}: {$e->getMessage()} (request {$e->requestId})");
}
```

An `ApiException` carries `statusCode`, `errorType`, `errorCode`, `param`,
`reason`, `requestId`, `rawBody` (the decoded error body, null when it was not
JSON), `httpBody` (the body as received) and `headers` (lowercased names). The envelope's `type` and `code` are `errorType` and
`errorCode` because `\Exception` already owns `$code`. `reason` is the finer
cause some codes carry: a `409 operation_pending` while a renewal payment is
settling has `reason === 'renewal_processing'`. Quote `requestId` to support.

The class is chosen by **HTTP status**, not by the envelope's `type`. An
unmatched route is a 404 with `errorType` `invalid_request_error` and
`errorCode` `route_not_found`, and it is a `NotFoundException`; a 4xx whose
body says `api_error` (as a proxy in front of the API can) is never a server
error. `ApiConnectionException`'s message has the query
string removed from every URL in it; with an injected PSR-18 client, that
client's own exception is on `getPrevious()`.

A bad argument caught before any request (a float decimal rate, an unknown
option, an unconfirmed purge, an empty id, a timeout above 600 seconds, a body
with a string that is not UTF-8) is a
plain `\InvalidArgumentException`, not a `BillKitException`: it is a bug in the
calling code.

A 2xx whose body is not JSON is thrown as `ApiException` itself, with the body
in `httpBody`. It is nearly always something answering in the API's place: a
captive portal, a proxy's error page, or a `baseUrl` that is not the API.

### Faking errors in your tests

The exception constructors are internal. Build one with
`ApiException::factory(int $status, array $error = [], array $headers = [])`,
which returns the subclass a real response with that status would produce:

```php
use BillKit\Exception\ApiException;

$e = ApiException::factory(409, ['code' => 'operation_pending', 'reason' => 'renewal_processing']);
// a ConflictException, errorCode 'operation_pending'
$limited = ApiException::factory(429, [], ['Retry-After' => '5']);
// a RateLimitException, retryAfter 5.0
```

`$error` is the envelope's `error` object (`type`, `code`, `message`, `param`,
`reason`); header names match without regard to case.

## Retries and idempotency

Connection failures, timeouts and 5xx except 501 are retried with jittered
exponential backoff, four attempts in total by default. A 501 is a permanent
answer (the PDF routes' `rendering_pending`), so it is thrown at once. A 429 is retried only when its
`Retry-After` is 30 seconds or less. Every other 4xx fails at once, with one
exception below.

Every mutating call carries an `Idempotency-Key`. The SDK generates one per
logical call, before the first attempt, and every retry reuses it, so a retry
never charges twice. Pass your own with the `idempotency_key` option when *your*
retry has to converge on the same result across processes or restarts. A replay
returns the original response; a different body under the same key is a
`ConflictException`.

`409 idempotency_in_progress` is retried too: a request with the same key is
still in flight and may already have charged the customer, and the obvious
workaround, a fresh key, is what turns one charge into two. The retry reuses
the key, so it either loses the race again or replays the first call's result.

## Webhooks

```php
use BillKit\Exception\WebhookVerificationException;
use BillKit\Model\InvoicePaymentFailedEvent;
use BillKit\Model\SubscriptionUpdatedEvent;
use BillKit\Webhooks;

$secret = getenv('BILLKIT_WEBHOOK_SECRET');   // string|false
if (!is_string($secret) || $secret === '') {
    throw new \RuntimeException('BILLKIT_WEBHOOK_SECRET is not set.');
}

try {
    $event = Webhooks::verifySignature(
        payload: (string) file_get_contents('php://input'),   // the RAW body
        signatureHeader: $_SERVER['HTTP_BILLKIT_SIGNATURE'] ?? null,
        secret: $secret,
    );
} catch (WebhookVerificationException $e) {
    http_response_code(400);
    exit;
}

if ($event instanceof SubscriptionUpdatedEvent && isset($event->previous_attributes['status'])) {
    error_log("status went {$event->previous_attributes['status']} -> {$event->data?->status}");
} elseif ($event instanceof InvoicePaymentFailedEvent) {
    Dunning::start($event->data?->customer_id);
}
// Anything else: acknowledge and ignore.
```

`verifySignature()` checks the HMAC in constant time, accepts any of several
`v1=` signatures (a secret rotation sends two), refuses a timestamp more than
`toleranceSeconds` (300) from now, and returns the event decoded into its typed
class: one per event type this SDK version knows, each with a typed `data`
(`SubscriptionUpdatedEvent::$data` is a `Subscription`). A type this version
does not know comes back as a plain `BillKit\Model\Event`, never an exception;
`$event['type']` and the other array reads work on every event.

`data` is the object itself, not a `data.object` wrapper. `previous_attributes`
is always present: on an update it holds the fields that changed with their
values before the change, and it is `null` when there is nothing to report, so
test it for `null` rather than for presence.

`BillKit\EventType` holds every known type as a constant, and
`EventType::all()` lists them for `webhookEndpoints->create(['enabled_events' => ...])`.

Pass the raw body (`php://input`, or `$request->getContent()` in Laravel and
Symfony): a re-encoded payload has different bytes and will not verify.

## One-shot payments

A single charge with no mandate: no subscription, no renewal.

```php
$payment = $client->oneShotPayments->create([
    'customer_id'    => $customer->id,
    'amount_cents'   => 1999,
    'currency'       => 'EUR',
    'payment_method' => 'ideal',
    'success_url'    => 'https://app.example.com/done',
    'cancel_url'     => 'https://app.example.com/cart',
]);
header('Location: ' . $payment->redirect_url);

// It settles via one_shot_payment.succeeded / .failed. Refund it in full or in part:
$client->refunds->create(['one_shot_payment_id' => $payment->id, 'amount_cents' => 500]);
```

`refund_window_days` sets how long the charge stays refundable: `0` disables
refunds, the default is `30`, the maximum `365`. For a payment taken inside your
own page, create it with `'ui_mode' => 'embedded'` and **no** `payment_method`, and hand
only `$payment->client_secret` to `@billkit-eu/js`. The secret is on the create
response only.

## Test clocks

Virtual time for test mode. Create a clock, create customers on it, advance it:
trials end, renewals fall due and dunning runs for that clock's customers, and
the work is done before `advance()` returns.

```php
$clock = $client->testClocks->create(['frozen_time' => time(), 'name' => 'Trial ends']);
$customer = $client->customers->create(['email' => 'ada@example.com', 'test_clock_id' => $clock->id]);
$client->testClocks->advance($clock->id, ['frozen_time' => $clock->frozen_time + 15 * 86400]);
$client->testClocks->delete($clock->id); // a DeletedResource; its customers return to real time
```

## Invoice and credit-note PDFs

```php
file_put_contents('invoice.pdf', $client->invoices->retrievePdf('inv_123'));
file_put_contents('credit-note.pdf', $client->creditNotes->retrievePdf('cn_123'));
```

S3-backed deployments answer with a redirect to a presigned URL, which the SDK
follows without sending your API key to the storage host. A deployment with PDF
rendering disabled answers `501`, a `ServerException` with
`errorCode === 'rendering_pending'`; `retrieve()` still gives you the
structured document.

## Expanding a relation

Six routes take `expand`, which attaches the related object next to the id,
resolved for the whole page in one query:

```php
$page = $client->subscriptions->all(['expand' => ['customer', 'price']]);
$page->data[0]->customer?->name;

$payment = $client->payments->retrieve('pay_123', ['expand' => ['refund_eligibility']]);
$payment->refund_eligibility?->eligible;
```

`customers->all()` takes `stats`; `products` `prices`, `stats`, `default_price`;
`subscriptions` `customer`, `price`, `refund_eligibility`; `payments`
`customer`, `subscription`, `refund_eligibility` (retrieve only); `invoices`
`customer`; `events->all()` `customer`. An unknown relation is a `400` naming
the ones that work. A restricted key also needs the expanded resource's read
scope (`customers:read` for `customer`, say); without it the call is a `403`
`insufficient_scope` with `param` `expand`.

## When `null` means "clear this"

A few keys take an explicit `null` as a value that clears the field:
`vat_number` on `customers->setVatNumber()`, the address fields and
`registration_number` on `tenant->setBillingProfile()`, every field of
`tenant->setPortalBranding()`, `default_price_id`,
`description` and `marketing_features` on `products->update()`, the two refund
windows on `prices->update()`, `name` on `customers->update()`, `description` on
`webhookEndpoints->update()`, `max_redemptions`, `redeem_by`,
`applies_to_price_ids` and `min_amount_cents` on `coupons->update()`, and
`display_name` on `taxRates->update()`. Leave the key out to keep the stored
value. Every other `null` is stripped, because the API refuses a null on a
field it cannot clear. Your own `vat_id` can be set once; changing or clearing
it afterwards is a `400 vat_id_locked`.

```php
$client->customers->setVatNumber($id, ['vat_number' => null]);       // deregistered
$client->products->update($productId, ['default_price_id' => null]); // portal falls back to the newest price
```

## Deleting, archiving and erasing

`delete()` exists on `customers`, `webhookEndpoints` and `testClocks`, and
returns a `DeletedResource` (`id`, `object`, `deleted: true`) rather than the
object: it has left the API and 404s afterwards.

The catalogue is archived through its update route, because it has to stay
readable: `['active' => false]` on prices, products, tax rates and coupons.
`['status' => 'disabled']` on a webhook endpoint stops delivery and keeps the
endpoint, its secret and its history.

`customers->purge()` is the GDPR erasure: it keeps the financial records and
erases the person. It is irreversible, so it needs the confirmation spelled
out, and without it the SDK throws before sending anything:

```php
$purged = $client->customers->purge($customerId, ['confirmed' => true]);
$purged->purged; // true: a PurgedResource marker, not the customer
```

## Subscription state: two fields

`status` is where the subscription stands with its payments (`incomplete`,
`trialing`, `active`, `past_due`, `canceled`). `renewal_state` is what happens
at the end of the period (`auto_renew`, `paused`, `canceling`, `stopped`).
Pausing sets `renewal_state` and leaves `status` at `active`, because the
period is paid for. `serves_customer` is the API's answer to "grant access
now?", and is what to read.

```php
$paused = $client->subscriptions->all(['renewal_state' => ['paused']]);
```

`['status' => ['paused']]` is refused with an `InvalidRequestException`.
`status` and `renewal_state` here, `status` on disputes and `type` on events
take a list, sent comma-joined; a single string is sent as given.

## Metered billing

A metered price uses exactly one of `amount_cents` (whole minor units per unit),
`unit_amount_decimal` (finer than a minor unit, **as a string**) or tiers:

```php
$client->prices->create([
    'product_id' => $product->id, 'unit_amount_decimal' => '0.02', // 0.02 CENTS per unit
    'currency' => 'EUR', 'interval' => 'month', 'usage_type' => 'metered',
]);

$client->prices->create([
    'product_id' => $product->id, 'currency' => 'EUR', 'interval' => 'month',
    'usage_type' => 'metered', 'billing_scheme' => 'tiered', 'tiers_mode' => 'graduated',
    'tiers' => [
        ['up_to' => 1000, 'unit_amount_cents' => 1],
        ['up_to' => 'inf', 'unit_amount_decimal' => '0.5'],
    ],
]);
```

A `float` `unit_amount_decimal` throws `\InvalidArgumentException` before the
request: a PHP float cannot hold 0.0002 exactly, and coercing would silently
mis-price the rates that do not round-trip. An `int` is accepted and sent as a
string. `tiers_mode` is required; the last band is `'up_to' => 'inf'`.

```php
$client->subscriptions->createUsageRecord($sub->id, [
    'quantity' => 1200,
    'identifier' => 'job-2026-09-19T10:00Z',  // your id for what you are metering
]);

$summary = $client->subscriptions->retrieveUsageSummary($sub->id);
if ($summary->will_charge) {
    echo $summary->gross_cents;
}
```

The `idempotency_key` option covers a retry of one HTTP request; `identifier`
covers a retry of *your* call (a replayed job, a queue delivering twice), and a
second report of the same identifier returns the first record. Check
`will_charge` before promising an amount: a period under
`minimum_charge_cents` is not charged and rolls into the next.

## Method reference

Every method ends with `array $params = [], array $opts = []`; ids come first.

| `$client->…` | Method | Returns |
|---|---|---|
| `apiKeys` | `create`, `retrieve`, `revoke` | `ApiKey` |
| | `all` / `autoPagingIterator` | `Collection<ApiKey>` / `Generator<ApiKey>` |
| `auditLogs` | `retrieve` | `AuditLog` |
| | `all` / `autoPagingIterator` (`action`, `resource_type`, `resource_id`, `actor_id`) | `AuditLog`s |
| `billingPortalSessions` | `create` | `BillingPortalSession` |
| | `revoke` | `BillingPortalSessionRevoke` |
| `checkoutSessions` | `create`, `retrieve` | `CheckoutSession` |
| `coupons` | `create`, `retrieve`, `update` | `Coupon` |
| | `validate` | `CouponValidation` |
| | `all` / `autoPagingIterator` | `Coupon`s |
| `creditNotes` | `retrieve` | `CreditNote` |
| | `retrievePdf` | `string` |
| | `all` / `autoPagingIterator` (`invoice_id`, `customer_id`) | `CreditNote`s |
| `customers` | `create`, `retrieve`, `update`, `setVatNumber` | `Customer` |
| | `delete` | `DeletedResource` |
| | `purge` | `PurgedResource` |
| | `all` / `autoPagingIterator` (`provisional`, `expand`) | `Customer`s |
| `disputes` | `retrieve` | `Dispute` |
| | `all` / `autoPagingIterator` (`status`, `payment_id`) | `Dispute`s |
| `events` | `retrieve` | `Event` (typed subclass when known) |
| | `all` / `autoPagingIterator` (`type`, `expand`) | `Event`s |
| `invoices` | `retrieve`, `void` | `Invoice` |
| | `retrievePdf` | `string` |
| | `sendEmail` | `InvoiceEmail` |
| | `all` / `autoPagingIterator` (`customer_id`, `subscription_id`, `payment_id`, `status`) | `Invoice`s |
| `oneShotPayments` | `create`, `retrieve` | `OneShotPayment` |
| | `all` / `autoPagingIterator` (`customer_id`, `status`) | `OneShotPayment`s |
| `payments` | `retrieve` | `Payment` |
| | `retrieveProvider` | `ProviderPayload` |
| | `all` / `autoPagingIterator` (`customer_id`) | `Payment`s |
| `prices` | `create`, `retrieve`, `update` | `Price` |
| | `all` / `autoPagingIterator` (`product_id`) | `Price`s |
| `products` | `create`, `retrieve`, `update` | `Product` |
| | `all` / `autoPagingIterator` | `Product`s |
| `refunds` | `create`, `retrieve` | `Refund` |
| | `all` / `autoPagingIterator` | `Refund`s |
| `subscriptions` | `retrieve`, `cancel`, `pause`, `resume`, `reactivate`, `update` | `Subscription` |
| | `previewUpdate` | `PlanChangePreview` |
| | `reauthorizePaymentMethod` | `Reauthorization` |
| | `createUsageRecord` | `UsageRecord` |
| | `listUsageRecords` / `autoPagingIteratorUsageRecords` (`invoice_id`) | `UsageRecord`s |
| | `retrieveUsageSummary` | `UsageSummary` |
| | `all` / `autoPagingIterator` (`customer_id`, `status`, `renewal_state`) | `Subscription`s |
| `taxRates` | `create`, `retrieve`, `update` | `TaxRate` |
| | `all` / `autoPagingIterator` | `TaxRate`s |
| `tenant` | `retrieveCapabilities` | `TenantCapabilities` |
| | `retrievePortalBranding`, `setPortalBranding` | `TenantPortalBranding` |
| | `retrieveBillingProfile`, `setBillingProfile` | `TenantBillingProfile` |
| | `rotateProviderCredential` | `TenantProviderCredential` |
| | `export` | `string` |
| `testClocks` | `create`, `retrieve`, `advance` | `TestClock` |
| | `delete` | `DeletedResource` |
| | `all` / `autoPagingIterator` | `TestClock`s |
| `webhookEndpoints` | `create`, `retrieve`, `update`, `rotateSecret` | `WebhookEndpoint` |
| | `delete` | `DeletedResource` |
| | `listEventTypes` | `EventTypeList` |
| | `all` / `autoPagingIterator` | `WebhookEndpoint`s |
| | `listDeliveries` / `autoPagingIteratorDeliveries` (endpoint id first) | `WebhookDelivery`s |
| | `retrieveDelivery`, `redeliver` (endpoint id, delivery id) | `WebhookDelivery` |

Plus `BillKit\Webhooks::verifySignature()` (static) and `BillKit\EventType`.
Every id argument must be non-empty: `retrieve('')` throws
`\InvalidArgumentException` before any request.

`subscriptions->update()` and `previewUpdate()` take `['target_price_id' => ...]`;
`reauthorizePaymentMethod()` takes `['return_url' => ...]`. The returned
subscription is usually still on the old price: the switch lands when the
proration charge settles, and `subscription.updated` reports it.

## Custom HTTP client (PSR-18)

```php
use BillKit\BillKitClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;

$factory = new HttpFactory();
$client = new BillKitClient(
    apiKey: 'bk_test_...',
    httpClient: new GuzzleClient(['timeout' => 30]),
    requestFactory: $factory,
    streamFactory: $factory,
);
```

The bundled curl transport is the default. An injected client owns its own
timeouts, so the `timeout` option does not reach it.

## Logging (PSR-3)

The SDK is **silent by default** (a `NullLogger`). Inject any PSR-3 logger to
opt in:

```php
$client = new BillKitClient(apiKey: 'bk_test_...', logger: $monolog);
```

- **debug**: one record per attempt and one per response (`method`, `url`,
  `attempt`, `status`, `duration_ms`, `request_id`).
- **warning**: one record per retry, with the reason and the delay.

**Never logged:** your API key or the `Authorization` header; request and
response bodies; the query string (list filters carry values like `email=`);
and the final failure, which is thrown to you instead.

Using Laravel? [`billkit-eu/billkit-laravel`](https://github.com/billkit-eu/billkit-laravel)
wires a log channel for you.

## Development

```bash
composer install
composer test        # PHPUnit
composer analyse     # PHPStan (level max)
composer cs:check    # php-cs-fixer (dry-run)
composer generate    # regenerate src/Model from the OpenAPI document
```

## License

Apache-2.0
