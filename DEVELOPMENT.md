# Developing `billkit-eu/billkit-php`

For changing this package. If you are only *using* the SDK, read `AGENTS.md`.

A near-mechanical port of the Node SDK (`../node/src`); keep behaviour in
lockstep with node and python.

- **PHP 8.1+**, PSR-4 (`BillKit\` → `src/`). No hard runtime deps beyond
  `ext-curl` / `ext-json` (+ interface-only `psr/http-client`,
  `psr/http-factory`, `psr/log`).

## Layout

- `src/BillKitClient.php`: top-level client; one public readonly prop per resource.
- `src/Transport.php`: HTTP + retry + error mapping. Default = bundled **curl**;
  inject a PSR-18 client + PSR-17 factories to override (the test seam).
- `src/RetryPolicy.php`: backoff + `shouldRetry` (ported from `retry.ts`).
- `src/Collection.php`: `autoPagingIterator()` `Generator`.
- `src/DecimalRate.php`: refuses a float where a sub-minor-unit rate belongs.
- `src/Webhooks.php`: `verifySignature()`; must match `api/.../core/signing.py`.
- `src/Version.php`: `VERSION` constant (the publish workflow gates on it).
- `src/Exception/*`: `BillKitException` + subclasses; `fromResponse()` maps
  `type`/status → class. NB: envelope `type`/`code` are exposed as
  `errorType`/`errorCode` (PHP reserves `Exception::$code`).
- `src/Resource/*`: one class per resource family; `BaseResource` has the
  `get/post/postEmpty/postFixed/del` helpers.

## Conventions

- Every method returns the decoded JSON as `array<string, mixed>`, with **no model
  classes** (matches node/python).
- Resource params are plain assoc arrays; a reserved `idempotency_key` entry is
  lifted into the header. Lifecycle verbs take `?string $idempotencyKey` positionally.
- List method is `all()`; auto-pagination is `autoPagingIterator()`.
- `null` values are stripped from request bodies; `false`/`0`/`''` are preserved.
- **A sub-minor-unit rate is a string, and a float is refused, not coerced.**
  `DecimalRate::normalizePriceParams()` guards `unit_amount_decimal` at the price
  level and inside every tier, throwing `\InvalidArgumentException` before the
  request. An `int` is stringified because it is exact. This matters more here
  than in the other two clients: node's type system rejects a number at compile
  time and python has `Decimal`, while PHP has neither, so the guard is the only
  thing between `0.0002` and a JSON number on the wire.

  Note that PHP's array **value** semantics mean the caller's tier table is
  already a copy by the time the guard sees it. Node and python have to copy
  explicitly; do not port that copy back here as if it were load-bearing, and do
  not write a test asserting it — such a test cannot fail in PHP.

## Quality gates (all must pass; CI matrix = PHP 8.1-8.4)

```bash
composer install
composer test      # PHPUnit ^10.5; inject MockHttpClient (tests/Support), FAST retry
composer analyse   # PHPStan level max, checked at phpVersion 8.1
composer cs:check  # php-cs-fixer (@PSR12)
```

Gotchas: PHPStan level max wants `@param`/`@return` on **separate** lines and a
value type on every `array`. Locally you may run on a newer PHP than 8.1 and that's
fine (8.1 syntax runs forward); PHPStan still checks against 8.1.

## Release

Bump `src/Version.php` + `CHANGELOG.md`, tag `sdk-php-vX.Y.Z`. Packagist's GitHub
App syncs the tag (no registry push); the publish workflow is the gate.
`composer.json` deliberately carries **no** `version` field — Packagist reads the
tag, and a second copy is a second thing to forget.

**`sdk/laravel` depends on this package through Packagist**, currently
`>=0.3.0 <1`. `check_sibling_range()` in `../scripts/lib.sh` refuses a release
whose range does not admit the version in this tree, so a bump here that laravel
has not followed fails loudly. Note what that check cannot see: a range that is
too *loose* still passes, so when laravel starts calling something new here, its
lower bound has to be raised by hand or an install can satisfy the constraint and
still lack the method. Never use a caret on a 0.x version for this: `^0.2` pins
the minor in Composer and would have resolved the old client silently.

Release php first, since laravel's published dependency has to exist before
laravel does.
