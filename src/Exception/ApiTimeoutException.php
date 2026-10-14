<?php

declare(strict_types=1);

namespace BillKit\Exception;

/**
 * The request timed out before a response arrived.
 *
 * A subclass of {@see ApiConnectionException}, so code that already handles
 * connection failures handles this too. A timed-out mutating call may still
 * have been applied server-side; the SDK retried it under the same
 * `Idempotency-Key`, so retrying it yourself with that key is safe.
 */
class ApiTimeoutException extends ApiConnectionException
{
}
