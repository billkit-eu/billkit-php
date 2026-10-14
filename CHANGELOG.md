# Changelog

All notable changes to the BillKit PHP SDK will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Versioning is independent of the Node and Python SDKs; each ships on its own
cadence.

## [Unreleased]

## [1.0.0] - 2026-10-14

The first stable release, and a deliberate break from 0.x. Composer's `^0.9`
does not admit 1.0, so nothing upgrades by accident; read the list below before
you widen your constraint to `^1.0`. From here on, the SDK follows SemVer: a
breaking change waits for 2.0.

### The 1.0 API contract

The API froze its `/v1` contract for 1.0; these are the parts a caller sees.

- Request validation is a `400` everywhere (`InvalidRequestException`); the API no longer
  answers `422`, though one would still map to `InvalidRequestException`.
- New error codes in `ApiException::$errorCode`: `parameter_unknown` (an unknown body
  field or query parameter, named in `param`), `route_not_found` (404),
  `method_not_allowed` (405), `state_conflict` (409, replacing a 400
  `parameter_invalid` for a state refusal such as pausing a canceled
  subscription; `reason` is unchanged) and `mode_mismatch` (403).
- An unmatched route is a 404 with type `invalid_request_error` and code
  `route_not_found`; the status still picks the class, so it is the
  not-found error.
- A checkout session's `object` is `checkout.session` (was
  `checkout_session`).
- `subscriptions->createUsageRecord()` is answered with `200` (was `201`); the return value is
  unchanged.
- API key, audit log and webhook delivery ids are prefixed: `ak_`, `al_`,
  `whd_`.
- `next_charge_amount_cents` is filled on subscription list, retrieve and
  mutation responses and on every `subscription.*` webhook, not only on
  retrieve.
- `expand` needs the expanded resource's read scope; without it the call
  is a `403 insufficient_scope` with `param` `expand`.
- A payment the provider refuses is a `402` with type `payment_error` and code
  `payment_refused` (was `invalid_request_error` / `parameter_invalid` with
  `reason` `payment_refused`). It throws `PaymentException`.
- A missing required field is a `400` with code `parameter_missing`, naming the
  field in `param`.
- `refunds->create()` on a payment whose state rules a refund out (not paid,
  outside its refund window) is a `409` `ConflictException` with code
  `state_conflict` (was a `400`).
- Renamed fields, in requests, responses and webhook payloads alike:
  `payment_method` on checkout sessions and one-off payments (was `method`;
  the model constants are now `PAYMENT_METHOD_*`), `reversed_at` on disputes
  (was `reversed`), `test_clock_id` on customers (was `test_clock`).
- `subscriptions` `status` and `renewal_state`, `disputes` `status` and
  `events` `type` are list filters: pass a PHP list and it is sent
  comma-joined (`['status' => ['active', 'past_due']]`). One string is still
  sent as given. `invoices` and `oneShotPayments` take exactly one `status`,
  from a closed set.
- `percent_off` on a coupon takes up to two decimal places (more than 0, at
  most 100): send an `int` or a `float`. `Coupon::$percent_off` and
  `CouponValidation::$percent_off` are `?float`.
- `OneShotPayment::$metadata` is always an object, `[]` when none was set.
- `BillingPortalSession::$subscription_id` (and the revocation's) is nullable.
- `customers->purge()` returns a `PurgedResource`
  (`id`, `object: "customer"`, `deleted: true`, `purged: true`) instead of the
  erased customer, whose placeholder email would read as live data.
- `invoice.*` events carry the invoice's real `lines` in `data`.
- `Invoice::STATUS_DRAFT` is gone: the API never issues a draft.
- `ProviderPayload::$redacted` says when the stored provider payload was
  redacted.
- Every operation documents a `500` with the error envelope.

### Hardening

- A body that `json_encode()` cannot represent (a string that is not valid
  UTF-8, `INF`, `NAN`) throws `\InvalidArgumentException` naming the route,
  with the `\JsonException` as `getPrevious()`, before any request. A raw
  `\JsonException` escaped before. The value is not quoted in the message.
- `ApiException::$errorType` for a 404 or 409 with no error body is
  `not_found_error` / `conflict_error`, the API's own values (it was
  `invalid_request_error` / `conflict`), matching node and python.
- `Webhooks::verifySignature()` refuses a whitespace-only secret, as it
  already refused an empty one.
- A path id of `.` or `..` is refused with `\InvalidArgumentException`
  before any request; URL normalisation resolved them as dot segments.
- The README and AGENTS.md tier examples use `unit_amount_cents` and
  `flat_amount_cents`; `unit_amount` / `flat_amount` are refused by the API.
  The README says a 501 is not retried, which was already the behaviour.

### Upgrading from 0.x

**1. Responses are typed objects, and array access still works.** Every method
returns a generated class (`BillKit\Model\Customer`, `Subscription`, ...) with
typed, read-only properties. `$customer['id']` keeps working, so most code
needs no change. What breaks is code that needs a real `array`:

```php
// 0.x
function store(array $customer): void { ... }
store($client->customers->retrieve($id));
array_key_exists('name', $customer);
$customer['metadata']['plan'] = 'pro';

// 1.0
store($client->customers->retrieve($id)->toArray());   // or type the parameter as Customer
$customer->has('name');
$copy = $customer->toArray(); $copy['metadata']['plan'] = 'pro';   // objects are immutable
```

`is_array($response)` is now false, objects cannot be written to, and a
function typed `array` needs `->toArray()`. `json_encode($obj)` produces the
same JSON as before, except that an empty JSON-object field such as `metadata`
is now written as `{}`, as the API sent it, where the 0.x array gave `[]`.

Every typed property is nullable, `id` included, and reads `null` when the
payload lacks the field. Event payloads are stored snapshots, so an event
recorded before a field existed does not carry it.

**2. `all()` returns a `Collection`, not an array.** `$page['data']`,
`$page['has_more']` and `foreach ($page['data'] as ...)` still work, and the
items are typed objects. `foreach ($page as $item)` now iterates the items.

**3. One calling convention: `(..., array $params = [], array $opts = [])`.**
Request options moved out of `$params` into a final `$opts` array, and
`idempotency_key` in `$params` now throws rather than being lifted out.

```php
// 0.x
$client->refunds->create(['payment_id' => $id, 'idempotency_key' => 'r-1']);
$client->subscriptions->cancel($id, 'cancel-1');
$client->subscriptions->update($id, 'price_new', 'swap-1');
$client->subscriptions->previewUpdate($id, 'price_new');
$client->subscriptions->reauthorizePaymentMethod($id, $returnUrl);
$client->webhookEndpoints->delete($id, 'drop-1');

// 1.0
$client->refunds->create(['payment_id' => $id], ['idempotency_key' => 'r-1']);
$client->subscriptions->cancel($id, [], ['idempotency_key' => 'cancel-1']);
$client->subscriptions->update($id, ['target_price_id' => 'price_new'], ['idempotency_key' => 'swap-1']);
$client->subscriptions->previewUpdate($id, ['target_price_id' => 'price_new']);
$client->subscriptions->reauthorizePaymentMethod($id, ['return_url' => $returnUrl]);
$client->webhookEndpoints->delete($id, [], ['idempotency_key' => 'drop-1']);
```

The same applies to every positional `$idempotencyKey`: `apiKeys->revoke`,
`billingPortalSessions->revoke`, `customers->delete`, `invoices->sendEmail`,
`subscriptions->cancel` / `pause` / `resume` / `reactivate`,
`testClocks->delete`, `webhookEndpoints->rotateSecret` / `redeliver`. Request
options also gained `timeout` (seconds), `max_retries` and `headers`. An
unknown option throws `\InvalidArgumentException`.

**3a. Timeouts are in seconds**, as in Guzzle and stripe-php. The constructor
argument `int $timeoutMs` is now `float $timeout`:

```php
// 0.x
new BillKitClient(apiKey: $key, timeoutMs: 15_000);
// 1.0
new BillKitClient(apiKey: $key, timeout: 15.0);
```

The `timeout` request option is seconds too (`['timeout' => 2.5]`). A value
that is not a number greater than 0, or that is above 600 seconds, throws
`\InvalidArgumentException`.

**A positional 0.x timeout would now mean hours, and is refused.** The third
constructor argument was `$timeoutMs` and is now `$timeout`, so a positional
call still type-checks:

```php
// 0.x: 30 seconds
new BillKitClient($key, null, 30000);
// 1.0: would be 30000 seconds (8.3 hours), so it throws instead:
//   InvalidArgumentException: ... over the 600-second maximum. Timeouts are
//   seconds since 1.0 (0.x took milliseconds) ...
new BillKitClient($key, null, 30.0);
```

**4. `autoPagingIterator()` takes the same `$params` as `all()`.** Page size is
`limit`, and filters are the API's names:

```php
// 0.x
$client->events->autoPagingIterator(50, 'customer.created');
$client->subscriptions->autoPagingIterator(100, ['status' => 'active']);
$client->auditLogs->autoPagingIterator(resourceId: $id);
$client->subscriptions->autoPagingIteratorUsageRecords($id, 100, 'pending');

// 1.0
$client->events->autoPagingIterator(['limit' => 50, 'type' => 'customer.created']);
$client->subscriptions->autoPagingIterator(['limit' => 100, 'status' => 'active']);
$client->auditLogs->autoPagingIterator(['resource_id' => $id]);
$client->subscriptions->autoPagingIteratorUsageRecords($id, ['limit' => 100, 'invoice_id' => 'pending']);
```

Every iterator yields typed objects. `BillKit\Collection::autoPagingIterator()`
is now an instance method on a page (continue from a page you hold); the 0.x
static helper is gone.

**5. `customers->purge()` needs the confirmation.** 0.x sent
`confirmed: true` for you, which made the API's guard against an accidental
erasure guard nothing. Without it, 1.0 throws `\InvalidArgumentException` and
sends nothing.

```php
// 0.x
$client->customers->purge($id);
// 1.0
$client->customers->purge($id, ['confirmed' => true]);
```

**6. Exceptions: renamed, regrouped, and `WebhookVerificationException` joins
the family.**

> **`catch (ApiException $e)` now catches 4xx errors too.** In 0.x
> `ApiException` was the parent of `ServerException` only, so a handler written
> as `catch (ApiException)` meant "BillKit had a server error". In 1.0 it is
> the parent of every HTTP error class: a 404, a 409 or a 422 now lands in that
> same handler, and anything there that retries, alerts or reports an outage
> fires on your own request mistakes. **Catch `ServerException` for the old
> meaning.**

```php
// 0.x: a 5xx only
catch (ApiException $e) { /* page someone */ }
// 1.0: the same meaning
catch (ServerException $e) { /* page someone */ }
```

| 0.x | 1.0 |
|---|---|
| `PermissionException` | `PermissionDeniedException` |
| `ResourceMissingException` | `NotFoundException` |
| `ApiException` (parent of `ServerException` only) | `ApiException`, parent of **every** HTTP error class |
| `WebhookVerificationException extends \RuntimeException` | `extends BillKitException` |
| `BillKitException::fromResponse()` | `ApiException::fromResponse()` (internal) |
| (none) | `ApiTimeoutException extends ApiConnectionException` |

HTTP error properties (`statusCode`, `errorType`, `errorCode`, `param`,
`reason`, `requestId`, `rawBody`) moved from `BillKitException` to
`ApiException`, which adds `headers`; `statusCode` is a non-null `int`.
`catch (BillKitException $e)` still catches every API error, and now also
catches a webhook verification failure: if you relied on it not doing so,
catch `WebhookVerificationException` first. Constructors are internal and
take `$previous` last.

**7. Webhooks return typed events.** `Webhooks::verifySignature()` returns a
`BillKit\Model\Event`, as its typed subclass when the type is known
(`SubscriptionUpdatedEvent`, whose `data` is a `Subscription`). Array reads
(`$event['type']`, `$event['data']['id']`) still work. `previous_attributes`
is now always present and `null` when nothing changed, rather than absent.

**8. Renamed methods.**

| 0.x | 1.0 |
|---|---|
| `tenant->capabilities()` | `tenant->retrieveCapabilities()` |
| `tenant->portalBranding()` | `tenant->retrievePortalBranding()` |
| `tenant->billingProfile()` | `tenant->retrieveBillingProfile()` |
| `webhookEndpoints->allDeliveries()` | `webhookEndpoints->listDeliveries()`, the name node uses and the `list*` spelling of `listUsageRecords()` / `listEventTypes()` |

**9. Coupons follow API 1.0.** `discount_value` is gone: send `percent_off`
(up to two decimal places) or `amount_off_cents` (minor units), and read the same fields
back. `discount_type` is optional and is `'percent'` or `'fixed_amount'`
(was `'fixed_cents'`).

**10. Internals are internal.** `BillKitClient::$transport` is private, and
`Transport`, `RequestOptions`, `BaseResource` (and its constructor), the
`RetryPolicy` decision methods and `Model\Registry` are marked `@internal`.
`new RetryPolicy(...)` and `withMaxAttempts()` stay public.

**11. A 2xx response whose body is not JSON throws.** 0.x decoded it to an
empty array. It is nearly always something answering in the API's place (a
captive portal, a proxy's error page, a `baseUrl` that is not the API), and
1.0 throws `ApiException` itself, with the status, headers and the body as
received in `httpBody`. An empty 2xx body is still an empty result.

**12. Renamed fields.** Request keys and response properties moved together:

| 0.x | 1.0 |
|---|---|
| `checkoutSessions->create(['method' => ...])`, `$session->method` | `'payment_method'`, `$session->payment_method` |
| `oneShotPayments->create(['method' => ...])`, `$payment->method` | `'payment_method'`, `$payment->payment_method` |
| `customers->create(['test_clock' => ...])`, `$customer->test_clock` | `'test_clock_id'`, `$customer->test_clock_id` |
| `$dispute->reversed` | `$dispute->reversed_at` |
| `CheckoutSession::METHOD_*`, `OneShotPayment::METHOD_*` | `PAYMENT_METHOD_*` |
| `customers->purge()` returns `Customer` | returns `PurgedResource` |

**13. Build the client where it is used.** A missing API key has always thrown
from the constructor, and 1.0 adds a whitespace-only one (`'  '`, a stray
newline from a secrets file), which 0.x accepted and sent as a broken bearer
token. Either way the throw happens wherever the client is built, which
matters when one object both calls the API and verifies webhooks: built
eagerly in its constructor, a missing key takes the webhook route down with
it, although verification only ever needed the signing secret. One
integration answered every delivery with a 500 instead of a 401 that way.
`Webhooks::verifySignature()` needs no client, so build `BillKitClient` lazily,
on first use, and pass `null` (or leave `apiKey` out) to read
`BILLKIT_API_KEY` rather than `getenv('BILLKIT_API_KEY') ?: ''`.

### Added
- `ApiException::factory(int $status, array $error = [], array $headers = [])`,
  the supported way to build an HTTP error in your own tests. It returns the
  subclass a real response with that status would produce.
- `ApiException::$httpBody`, the response body exactly as received.
- `PaymentException`, thrown for a 402: `errorCode` is `payment_refused` when
  the payment provider declined a charge or refund (its reason is in the
  message), or `tenant_suspended` when the account behind the key is
  suspended. A 402 was an `InvalidRequestException` before, which told the
  caller to change a request that was not at fault. Not retried.
- `Collection` can be serialized (`Cache::remember()`); a restored page holds
  its items but cannot fetch further, and its `autoPagingIterator()` throws
  `\LogicException` after them when `has_more` is true. A page built with
  `Collection::constructFrom()` behaves the same way. An undeclared field of a
  page is readable as `$page->field`.
- Typed response classes in `BillKit\Model`, generated from the published
  OpenAPI document by `tools/generate-models.php`; a test fails when
  regenerating would change them. Enum values are class constants
  (`Subscription::STATUS_ACTIVE`); enums stay open strings.
- One typed event class per webhook event type, and `Registry`-driven decoding
  of `events->retrieve()` / `events->all()` into the same classes.
- Request options `timeout`, `max_retries` and `headers`.
- `ApiTimeoutException`, and `ApiException::$headers`.
- `Collection` with `data`, `has_more`, `isEmpty()`, `toArray()` and an
  instance `autoPagingIterator()` that keeps the page's filters and options.
- `BillKitObject::has()`, `keys()`, `toArray()` and `constructFrom()`.
- `.gitattributes`, so the Packagist dist ships only the library, its licence
  and its docs.

- `$client->testClocks`: `create`, `retrieve`, `advance`, `delete`, `all` and
  `autoPagingIterator` over `/v1/test_clocks`. Test mode only. Attach a
  customer with `customers->create(['test_clock' => ...])`.
- `BillKit\EventType` constants (e.g. `EventType::INVOICE_PAID`), plus
  `EventType::all()` and `EventType::isKnown()`, checked against the API's
  event catalogue. An unknown type is still never an error: a newer API can
  deliver one this list does not name.
- `ApiException::$reason`, the envelope's finer-grained cause. A
  `409 operation_pending` from `subscriptions->cancel`, `pause` or `update`
  while a renewal payment is still settling carries `'renewal_processing'`.
- `coupons->create(['currency' => ...])` is documented (the currency of a
  fixed-amount discount and of `min_amount_cents`; required with either when
  the account has several currencies).

### Changed
- `composer.json` requires `php: ^8.1` (was `>=8.1`).
- `ext-curl` moved from `require` to `suggest`: only the bundled transport
  needs it, and a host that injects a PSR-18 client can now install the SDK
  without it. With neither, constructing the client throws a
  `\RuntimeException` naming both ways out.
- The `php-http/discovery` suggestion is gone. Nothing in the SDK uses it;
  pass a PSR-18 client and PSR-17 factories explicitly.
- Query keys are forwarded as given, as node does (python sends one through
  `extra_query=`), including one this version does not know, so a filter the API adds in a later 1.x works
  without upgrading the SDK. A key the route does not take is the API's
  `400 parameter_unknown` (`InvalidRequestException`, key in `param`). Query
  values are still type-checked locally. The generated
  `BillKit\Model\QueryParameters` (internal) is removed.
- A query value with no query-string form (an object, a nested array) throws
  `\InvalidArgumentException` instead of being cast.

### Fixed
- A whitespace-only API key (`'  '`, `"\n"`) is refused at construction, by
  `BillKitClient` and `Transport` both, as node and python refuse it. It was
  accepted and sent as `Authorization: Bearer   `, so every call failed with
  a 401. A whitespace-only `BILLKIT_API_KEY` counts as unset.
- An empty array in a field the API types as an object (`metadata` on every
  route that takes it, `theme` and `capabilities` on
  `tenant->setPortalBranding()`) is sent as `{}`. `json_encode([])` wrote
  `[]`, a list, and the API refused the call with a 400, so
  `['metadata' => []]` could not empty a customer's metadata. The fields are
  generated per route from the OpenAPI document into
  `BillKit\Model\RequestMaps` (internal); a list such as `enabled_events`
  keeps its `[]`.
- `coupons->validate()` dropped every key but `code`, `price_id` and
  `amount_cents`, so a `currency` never reached the API. It is now sent; a
  coupon in another currency comes back `valid: false` with
  `reason: 'coupon_currency_mismatch'`.
- A blank or whitespace-only `idempotency_key` is treated as unset and replaced
  with a generated key. It used to send an empty `Idempotency-Key` header while
  the SDK still retried the POST.
- An empty or whitespace-only id (`customers->retrieve('')`) throws
  `\InvalidArgumentException` before any request, instead of calling
  `/v1/customers/`.
- `tenant->setPortalBranding()` can clear a field: an explicit `null` on any of
  `business_name`, `support_email`, `logo_url`, `theme` and `capabilities` is
  sent rather than stripped. An empty string was never a clear (it is a 422).
- `tenant->setBillingProfile()` sends the body as given, like every other
  update, so a misspelt key is refused by the API instead of silently dropped.
- `Webhooks::verifySignature()` throws `\InvalidArgumentException` for an empty
  secret (an HMAC under an empty key is one anyone can compute) or a negative
  tolerance, and refuses a `t=` longer than ten digits as malformed.
- A `CR`, `LF` or `NUL` in a `headers` option name or value, or in
  `idempotency_key`, throws `\InvalidArgumentException`. The bundled curl
  transport would otherwise have written a second header of the caller's
  choosing.
- `501 Not Implemented` is no longer retried: it is a permanent answer, and the
  retry budget only delayed it.

## [0.9.0] - 2026-10-09

### Added
- Embedded one-off payments: `oneShotPayments->create(['ui_mode' => 'embedded', ...])`, sent without `method`, creates no provider payment yet and returns a short-lived `client_secret` (with a null `redirect_url`) for the browser payment element in `@billkit-eu/js` / `@billkit-eu/react`, where the shopper picks the method. The body was already passed through as-is, so this release documents the mode and pins it with a test. Events, refunds and the payment id work as for a hosted one-shot. **Requires the matching API release.**

## [0.8.1] - 2026-09-26

### Changed
- An explicit `null` now also clears `applies_to_price_ids` (lifts the price restriction) and `min_amount_cents` (lifts the minimum) on `coupons->update()`, `marketing_features` (empties the list) on `products->update()`, and `refund_window_initial_days` / `refund_window_renewal_days` (drops the price's override) on `prices->update()`. The refund windows were already clearable in the API, but the null was stripped, so the clear never reached it. Every other null is still stripped, because the API refuses a null there.
  **Upgrade note:** as in 0.8.0, a null passed through from your own data clears the field: `['min_amount_cents' => $row->min]` with `$row->min` sometimes `null` now lifts the coupon's minimum, which widens who can redeem it. Omit the field when you mean "leave it".
  **Requires the matching API release.** An older API accepts the null on `applies_to_price_ids`, `min_amount_cents` and `marketing_features` and leaves the value in place.
- Every other update field refuses an explicit null with a `400` naming the field (the API used to ignore it). The SDK never sends one there; omit the field to leave it unchanged.

## [0.8.0] - 2026-09-26

### Added
- `oneShotPayments->all()` and `oneShotPayments->autoPagingIterator()` for `GET /v1/checkout/one_shot`, newest first, filtered by `customer_id` and `status`. The iterator carries both filters onto every page.
- `payments->retrieve()` documents the `refund_eligibility` expansion: whether a refund of the remaining balance would succeed now, with `eligible`, `amount_cents`, `currency`, `days_remaining`, `window_ends_at` and a `reason` (`not_paid`, `unrefundable_type`, `window_expired`, `fully_refunded`, `disputed`, `operation_pending`, `plan_change_pending`) when it would not. Retrieve only; `payments->all()` refuses it.

### Changed
- An explicit `null` now clears `description` on `products->update()`, `name` on `customers->update()`, `description` on `webhookEndpoints->update()`, `max_redemptions` and `redeem_by` on `coupons->update()`, and `display_name` on `taxRates->update()`. The null used to be stripped like every other null in a params array, so the clear never reached the API. An absent key still leaves the stored value alone, and other null values are still stripped.
  **Upgrade note:** a `null` you pass through from your own data now clears the field. `['name' => $user->name]` with `$user->name` sometimes `null` used to leave the name alone and now erases it; leave the key out when you mean "leave it".

## [0.7.1] - 2026-09-25

### Added
- **`products->update()` documents `default_price_id`**, the price the billing portal offers on that price's interval. It must be an active price of the same product.
- `products->retrieve()` and `products->all()` document the `default_price` expansion alongside `prices` and `stats`.

### Fixed
- **`products->update($id, ['default_price_id' => null])` clears the default.** The null used to be stripped like every other null in a params array, so the clear never reached the API and the call silently left the default in place. A present-and-null `default_price_id` is now sent as an explicit JSON null; an absent key still leaves the default alone, and other null values are still stripped.

### Changed
- `tenant->setBillingProfile()` documents that the tenant `vat_id` is set once: changing it or clearing it with `null` afterwards throws `InvalidRequestException` (`vat_id_locked` on `vat_id`) and writes nothing, and support changes it. The address fields and `registration_number` still clear with `null`.

## [0.7.0] - 2026-09-23

### Fixed
- **Every caller-supplied path id is percent-encoded** by `BaseResource::p()`. An id carrying `/`, `?` or `#` used to rewrite the request onto a different route; it is now a clean `404`.
- **`ApiConnectionException` keeps the PSR-18 client's own exception as `getPrevious()`.** The message is sanitised of query strings and generic ("cURL error 6"), so dropping the original left nothing to diagnose with.
- **`billingPortalSessions->create()` prunes nulls instead of sending a fixed two-key body**, so a new field on the route is reachable without an SDK change.
- The `>=0.3.0 <1` laravel dependency range quoted in `DEVELOPMENT.md` had not followed `composer.json`.
- `Webhooks::verifySignature` reports `t=0` as a malformed timestamp rather than letting it fall through to the tolerance window, which is what node has always said. Both refused it; only the message differed.

### Added
- **`$client->apiKeys`**: `create`, `retrieve`, `revoke`, `all`, `autoPagingIterator`. The secret is returned once, on create.
- **`invoices->sendEmail($id)`** for `POST /v1/invoices/{id}/email`, which re-sends the "your invoice is ready" email with a fresh portal link.
- **`payments->retrieveProvider($id)`** for `GET /v1/payments/{id}/provider`: the provider's live record, which answers `available: false` rather than erroring when it cannot be read.
- **`tenant->billingProfile()` / `tenant->setBillingProfile()`** for the seller's country, VAT id and invoice address.
- **`tenant->export()`** returns the account's full JSON export as raw bytes, through the same binary path the PDFs use.
- **`webhookEndpoints->listEventTypes()`** for the deliverable-event catalogue `enabled_events` is validated against.
- **`expand`** on `customers->all()`, `products`, `subscriptions`, `payments`, `invoices` and `events->all()`, and as a second argument on `products->retrieve()`, `subscriptions->retrieve()`, `payments->retrieve()` and `invoices->retrieve()`. A list value is joined with commas by the transport.
- **List filters carried onto every page**: `payments->autoPagingIterator($pageSize, $customerId)`, `disputes->autoPagingIterator($pageSize, $status, $paymentId)`, `invoices->autoPagingIterator($pageSize, $filters)` and `prices->autoPagingIterator($pageSize, $productId)`.
- `checkoutSessions->create()` documents `country`, which is what lets VAT apply to the first charge on the hosted flow; `billingPortalSessions->create()` documents `deliver_email`.

### Changed
- **`prices->update()` documents every field `PriceUpdate` accepts** (`active`, `metadata`, `tax_behavior`, `payment_methods`, `refund_on_cancel`, `refund_window_initial_days`, `refund_window_renewal_days`), all optional, where the docblock had said `active` was the only one.
- **`customers->setVatNumber(['vat_number' => null])` clears the registration**: that one null is sent as an explicit JSON null rather than stripped. `country_code` is still dropped when null. An array without the `vat_number` key is refused with `InvalidArgumentException` rather than read as a clear.
- **`tenant->setBillingProfile()` reads a present-but-null key as a clear** and an absent key as "leave it alone", matching `TenantBillingProfileUpdate`.
- `coupons->create()` documents the API's two `discount_type` literals, `percent` and `fixed_cents`.

## [0.6.0] - 2026-09-23

### Added
- **`auditLogs->autoPagingIterator()` takes a `$resourceId` scope.**
  - It answers "everything that ever happened to this customer", which is the
    question an audit log mostly exists for.
  - The API has always accepted a `resource_id` filter and `all()` could
    always forward it, because `all()` takes an array, but the iterator named
    only three of the four, so a scoped walk meant dropping back to manual
    pagination.
  - Matches exactly, and combines with `$resourceType` rather than replacing
    it.
  - **It is the LAST parameter**, not beside `$resourceType` where it belongs
    by meaning. These are positional: inserting it in the middle would
    silently re-bind the fourth argument of every existing four-argument call
    from an actor id to a resource id, and both are opaque strings no type
    check would catch. Reach for named arguments (`resourceId: $id`) and the
    order stops mattering.

### Changed
- **The integration suite now covers the payment-method vocabulary** on both
  request surfaces: `methods.recurring_vocabulary`,
  `methods.one_shot_vocabulary` and `methods.banktransfer_settles_in_days`.
  - No behaviour change. They describe what the server already accepted.
  - This package names no method anywhere, it forwards whatever array it is
    handed, so those scenarios are the only thing holding it to the same wire
    contract the node and python unions spell out.

## [0.5.0] - 2026-09-22

### Added
- **`invoices->retrievePdf($id)` and `creditNotes->retrievePdf($id)`**,
  returning the rendered document as a raw string. Blob-backed deployments
  stream the bytes inline and S3-backed ones answer `302` to a presigned URL,
  which the transport follows: curl drops the `Authorization` header on a
  cross-host hop (`CURLOPT_UNRESTRICTED_AUTH` stays off) and the PSR-18 path
  resolves the redirect itself, unauthenticated, so an injected client's own
  redirect policy cannot leak the API key to storage. Node has had this since
  0.3.0.

### Fixed
- **The exception class is now chosen by the HTTP status, not the envelope
  `type`.** A request that never reaches a route handler is serialised by the
  API's framework-level handler as `{"type": "api_error"}` *with a 4xx status*,
  so a plain `404`, a typo'd id, an SDK/API version skew, was thrown as
  `ServerException`. That told callers BillKit had broken when their own
  request was at fault, and `ServerException` is the class retry and alerting
  policies key on. `errorType` still carries the envelope value verbatim. Node
  already behaved this way; php and python now match.
- **`409 idempotency_in_progress` is retried.** It means a request carrying the
  same `Idempotency-Key` is still in flight, so the charge may already have
  happened; surfacing it immediately invited the one workaround that turns a
  single charge into two, retrying with a fresh key. The retry reuses the
  original key, so it either loses the race again or replays the first call's
  result. Every other 409 still fails fast. `RetryPolicy::shouldRetry()` takes
  a fourth `?string $errorCode` argument for this; it defaults to `null`.

## [0.4.0]

### Added
- **`$client->creditNotes`**, `retrieve`, `all` and `autoPagingIterator`. A
  credit note is the document that reverses an issued invoice; one is created
  for you when a refund settles, so there is no `create` here. `all` takes
  `invoice_id` to answer "was this sale credited, and by how much".
- **`$client->invoices->void($id)`**, records that an invoice was never owed.
  It keeps its number and stays readable; it just stops being a receivable.

  A **paid** invoice is refused with a `ConflictException` whose `errorCode` is
  `invoice_not_voidable`. Once the money has moved, "never owed" is not true.
  refund the payment instead, and a credit note is issued when the refund
  settles. Voiding twice is a no-op.

### Fixed
- **An empty request body was sent as `[]` instead of `{}`**, which the API
  rejects with a 422, "Input should be a valid dictionary or object to extract
  fields from". PHP cannot tell an empty map from an empty list and
  `json_encode([])` picks the list.

  This bit real calls, not just new ones: `$client->customers->update($id)`
  with no fields, any `update()` where every value you passed was `null` (they
  are stripped before encoding), `update()` given only an `idempotency_key`,
  and `$client->tenant->setPortalBranding()`. Only the empty case is affected.
  arrays that are genuinely lists, like `tiers` and `enabled_events`, are
  unchanged.

## [0.3.0]

Brings this client level with `@billkit-eu/sdk` 0.3.0 and `billkit-eu` 0.3.0.
All three server clients now send byte-identical request bodies for this
surface.

### Added
- **Metered pricing below one minor unit.** `$client->prices->create()` accepts
  `unit_amount_decimal`: a per-unit rate in **minor units** with up to 12
  decimal places, so `'0.02'` (0.02 cents, i.e. EUR 0.0002 per unit) is finally
  expressible. `amount_cents` is an integer and could never say it. Metered
  prices only.
- **Tiered pricing.** `billing_scheme => 'tiered'` with `tiers` and
  `tiers_mode`. `'graduated'` prices the units inside each band; `'volume'` lets
  the period total pick one band which then prices every unit. The same table
  under the two modes is a different bill, so the mode is required rather than
  defaulted. The last band must be `'up_to' => 'inf'`.
- **`identifier` on `createUsageRecord()`**, for the retry an idempotency key
  cannot catch. The key covers a retry of one HTTP request; `identifier` covers
  a retry of *your own* call, a job runner replaying a task, a queue delivering
  twice, which arrives as a genuinely new request with a new key. It is unique
  within the subscription, and a second report of the same identifier returns
  the first record unchanged rather than billing twice. If your reporting
  pipeline is at-least-once, this is the one that matters.
- **`$client->subscriptions->retrieveUsageSummary($id)`**, the money view of
  pending usage: `pending_quantity`, `net_cents` / `tax_cents` / `gross_cents`
  computed through the same rate or tier table the period close uses, and
  `will_charge`. Read `will_charge` before promising a customer an amount: a
  period under `minimum_charge_cents` (EUR 1.00) is **not** charged, because the
  provider would refuse it, and the usage rolls into the next period instead.
  Previously the only record of that decision was a server log line.
  `open_invoice_id` names an earlier cycle still unsettled.
- **`BillKit\DecimalRate`, and a float that cannot get through.** PHP has no
  decimal type, which makes this the client where the mistake is easiest to
  make: `'unit_amount_decimal' => 0.0002` is valid PHP and `json_encode` would
  put a JSON *number* on the wire. A float now throws
  `\InvalidArgumentException` before the request is sent, at the price level and
  inside every tier. It is not coerced: coercing would work for the rates that
  happen to round-trip through a double and silently mis-price the ones that do
  not. An `int` is accepted and stringified, because an integer is exact, only
  the float is a lie.

  `refund_on_cancel` is also documented on `prices->create()` for the first
  time. It has been server-side since the `0066` migration and the client always
  forwarded it, but nothing here said so.

## [0.2.1]

### Changed
- Documentation only. API keys are now `bk_live_…` / `bk_test_…` and webhook
  signing secrets `bkwhsec_…`; every example here used the previous
  Stripe-shaped `sk_`/`whsec_` spelling. No code in this package changed: it
  never parsed the prefix, it forwards the key as a bearer token.

## [0.2.0]

### Added
- `$client->prices->update($id, ['active' => false])` archives a price through
  `POST /v1/prices/{id}`. The price keeps its id and stays readable through
  `retrieve()` and `all()`, because subscriptions renew against it by id.
  Subscriptions already on it keep renewing; what stops is new business.
  Re-archiving is a no-op that returns the price unchanged, so a retry is safe.
  `active` is the only field a price accepts and `['active' => true]` is
  refused, because prices are immutable.
- `$client->subscriptions->autoPagingIterator()` takes a `$filters` array,
  carried onto every page request, matching `autoPagingIteratorUsageRecords()`.
  Filtering a multi-page walk after the fact means paging the whole history to
  find the tail of the match.

### Removed
- `delete()` on `products`, `prices`, `coupons`, `taxRates` and
  `webhookEndpoints`. None of them deleted anything: every one of those rows
  stays readable afterwards, which is why they have to. Retire them through the
  update route instead, `['active' => false]` for products, prices, tax rates
  and coupons, `['status' => 'disabled']` for webhook endpoints. The server no
  longer answers `DELETE` on those paths at all.

### Changed
- `$client->customers->delete($id)` returns
  `['id' => ..., 'object' => 'customer', 'deleted' => true]` instead of the
  customer. The customer leaves the API, so returning a body that reads like a
  live resource said the opposite of what happened.
- `$client->subscriptions->all()` documents `customer_id`, `status` and
  `renewal_state`, each of which the API accepts as a comma-separated list.
  Paused subscriptions are found with `['renewal_state' => 'paused']`;
  `['status' => 'paused']` is no longer accepted by the API and throws
  `InvalidRequestException`, because pausing sets `renewal_state` and leaves
  `status` at `active`.

## [0.1.0]

First public release.

### Added
- `BillKitClient` exposing every resource family: Customers, Products, Prices,
  CheckoutSessions, Subscriptions, Refunds, WebhookEndpoints, Events, Tenant,
  Coupons, TaxRates, Invoices, AuditLogs, Payments, BillingPortalSessions.
- Zero-dependency curl transport (ext-curl/ext-json only), with an optional
  injectable PSR-18 client + PSR-17 factories for custom transport.
- Cursor auto-pagination via `all()` + `autoPagingIterator()` generators.
- Typed exception hierarchy (`ApiConnectionException`, `AuthenticationException`,
  `PermissionException`, `ResourceMissingException`, `InvalidRequestException`,
  `ConflictException`, `RateLimitException`, `ServerException`) matching the
  BillKit error envelope.
- Automatic `Idempotency-Key` on every mutating call; overridable per call.
- Retry policy: 4 attempts, jittered exponential backoff, retries connection
  errors + 5xx (with idempotency) and 429 (respecting `Retry-After`).
- Configurable `timeoutMs` (default 30_000).
- `Webhooks::verifySignature()` for verifying `BillKit-Signature` webhooks
  (HMAC-SHA256, constant-time compare, 5-min replay protection).
- **Opt-in PSR-3 logging.** Pass a `logger:` to `BillKitClient` (or `Transport`)
  to see the request/retry lifecycle:

  ```php
  $client = new BillKitClient(apiKey: 'bk_test_...', logger: $monolog);
  ```

  Omitted (the default) the SDK uses a `NullLogger` and writes nowhere, so it
  can't take over your application's logging. `debug` fires once per HTTP
  attempt and once per response (`method`, `url`, `attempt`, `status`,
  `duration_ms`, `request_id`); `warning` fires once per retry with the reason
  and delay.

  API keys, request/response bodies and query strings are never passed to the
  logger, and the final failure is thrown rather than logged so you never get a
  duplicate entry.

- `psr/log` (`^1.1 || ^2.0 || ^3.0`) added to `require`. Interface-only and
  dependency-free, so the SDK's near-zero-dependency stance is intact, and it
  is what lets Monolog, Laravel's `Log` channel and Symfony's logger all drop
  straight in.

[Unreleased]: https://github.com/billkit-eu/billkit-php/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/billkit-eu/billkit-php/compare/v0.9.0...v1.0.0
[0.9.0]: https://github.com/billkit-eu/billkit-php/compare/v0.8.1...v0.9.0
[0.8.1]: https://github.com/billkit-eu/billkit-php/compare/v0.8.0...v0.8.1
[0.8.0]: https://github.com/billkit-eu/billkit-php/compare/v0.7.1...v0.8.0
[0.7.1]: https://github.com/billkit-eu/billkit-php/compare/v0.7.0...v0.7.1
[0.7.0]: https://github.com/billkit-eu/billkit-php/compare/v0.6.0...v0.7.0
[0.6.0]: https://github.com/billkit-eu/billkit-php/compare/v0.5.0...v0.6.0
[0.5.0]: https://github.com/billkit-eu/billkit-php/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/billkit-eu/billkit-php/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/billkit-eu/billkit-php/compare/v0.2.1...v0.3.0
[0.2.1]: https://github.com/billkit-eu/billkit-php/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/billkit-eu/billkit-php/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/billkit-eu/billkit-php/releases/tag/v0.1.0
