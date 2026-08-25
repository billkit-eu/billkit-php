<?php

declare(strict_types=1);

namespace BillKit;

/**
 * Retry policy for transient failures.
 *
 * Retries 5xx + connection errors with jittered exponential backoff.
 * 4xx (including 409 Idempotency-Key conflicts) are caller-fault and
 * never retried. The transport auto-generates an ``Idempotency-Key`` for
 * every mutating call so retrying a 5xx never double-charges.
 *
 * Behaviour is a straight port of the Node SDK's ``retry.ts``: same
 * defaults, same backoff formula, same 429/``Retry-After`` handling.
 */
final class RetryPolicy
{
    public function __construct(
        public readonly int $maxAttempts = 4,
        public readonly int $initialBackoffMs = 500,
        public readonly float $backoffMultiplier = 2.0,
        public readonly int $maxBackoffMs = 8000,
        public readonly ?int $maxRetryAfterMs = 30_000,
        public readonly float $jitter = 0.25,
    ) {
    }

    /**
     * Backoff before attempt ``$attempt`` (1-indexed: attempt 2 is the
     * first retry). Callers never ask for attempt=1.
     */
    public function backoffForMs(int $attempt): float
    {
        $base = $this->initialBackoffMs * ($this->backoffMultiplier ** ($attempt - 2));
        $capped = min($base, (float) $this->maxBackoffMs);
        $jitterRange = $capped * $this->jitter;
        // A random float in [-1, 1]; index-free so it survives without Math.random parity concerns.
        $rand = (mt_rand() / mt_getrandmax()) * 2 - 1;
        $jittered = $capped + $rand * $jitterRange;

        return max(0.0, $jittered);
    }

    public function shouldRetry(?int $status, int $attempt, ?float $retryAfterMs = null): bool
    {
        if ($attempt >= $this->maxAttempts) {
            return false;
        }
        if ($status === null) {
            return true; // connection error
        }
        if ($status === 429) {
            // 429 is retried only when the server supplies a short,
            // parseable Retry-After; otherwise surface the exception so
            // the caller decides. ``maxRetryAfterMs = null`` allows any.
            if ($retryAfterMs === null || $retryAfterMs < 0) {
                return false;
            }

            return $this->maxRetryAfterMs === null || $retryAfterMs <= $this->maxRetryAfterMs;
        }

        return $status >= 500;
    }
}
