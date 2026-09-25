# Changelog

All notable changes to the BillKit PHP SDK will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Versioning is independent of the Node and Python SDKs; each ships on its own
cadence.

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
    always forward it, because `all()` takes an array — but the iterator named
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
  - This package names no method anywhere — it forwards whatever array it is
    handed — so those scenarios are the only thing holding it to the same wire
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
  so a plain `404` — a typo'd id, an SDK/API version skew — was thrown as
  `ServerException`. That told callers BillKit had broken when their own
  request was at fault, and `ServerException` is the class retry and alerting
  policies key on. `errorType` still carries the envelope value verbatim. Node
  already behaved this way; php and python now match.
- **`409 idempotency_in_progress` is retried.** It means a request carrying the
  same `Idempotency-Key` is still in flight, so the charge may already have
  happened; surfacing it immediately invited the one workaround that turns a
  single charge into two — retrying with a fresh key. The retry reuses the
  original key, so it either loses the race again or replays the first call's
  result. Every other 409 still fails fast. `RetryPolicy::shouldRetry()` takes
  a fourth `?string $errorCode` argument for this; it defaults to `null`.

## [0.4.0]

### Added
- **`$client->creditNotes`** — `retrieve`, `all` and `autoPagingIterator`. A
  credit note is the document that reverses an issued invoice; one is created
  for you when a refund settles, so there is no `create` here. `all` takes
  `invoice_id` to answer "was this sale credited, and by how much".
- **`$client->invoices->void($id)`** — records that an invoice was never owed.
  It keeps its number and stays readable; it just stops being a receivable.

  A **paid** invoice is refused with a `ConflictException` whose `errorCode` is
  `invoice_not_voidable`. Once the money has moved, "never owed" is not true —
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
  and `$client->tenant->setPortalBranding()`. Only the empty case is affected —
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
  a retry of *your own* call — a job runner replaying a task, a queue delivering
  twice — which arrives as a genuinely new request with a new key. It is unique
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
  not. An `int` is accepted and stringified, because an integer is exact — only
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
  update route instead — `['active' => false]` for products, prices, tax rates
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

[Unreleased]: https://github.com/billkit-eu/billkit-php/compare/v0.6.0...HEAD
[0.6.0]: https://github.com/billkit-eu/billkit-php/compare/v0.5.0...v0.6.0
[0.5.0]: https://github.com/billkit-eu/billkit-php/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/billkit-eu/billkit-php/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/billkit-eu/billkit-php/compare/v0.2.1...v0.3.0
[0.2.1]: https://github.com/billkit-eu/billkit-php/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/billkit-eu/billkit-php/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/billkit-eu/billkit-php/releases/tag/v0.1.0
