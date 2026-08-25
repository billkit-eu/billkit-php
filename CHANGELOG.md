# Changelog

All notable changes to the BillKit PHP SDK will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Versioning is independent of the Node and Python SDKs; each ships on its own
cadence.

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
  $client = new BillKitClient(apiKey: 'sk_test_...', logger: $monolog);
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
