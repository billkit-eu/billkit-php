<?php

declare(strict_types=1);

namespace BillKit\Exception;

/**
 * 409: a state clash, or an `Idempotency-Key` reused with a different body.
 *
 * `409 idempotency_in_progress` is retried by the SDK before it ever
 * reaches you; see {@see \BillKit\RetryPolicy}.
 */
class ConflictException extends ApiException
{
}
