# Changelog

All notable changes to the BillKit PHP SDK will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Versioning is independent of the Node and Python SDKs; each ships on its own
cadence.

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

[Unreleased]: https://github.com/billkit-eu/billkit-php/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/billkit-eu/billkit-php/releases/tag/v0.1.0
