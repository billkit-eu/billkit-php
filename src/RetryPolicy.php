<?php

declare(strict_types=1);

namespace BillKit;

/**
 * Retry policy for transient failures.
 *
 * Retries 5xx + connection errors with jittered exponential backoff.
 * 4xx are caller-fault and never retried, with one deliberate exception:
 * ``409 idempotency_in_progress``. See {@see self::IN_PROGRESS_CODE}.
 *
 * The transport auto-generates an ``Idempotency-Key`` for every mutating call
 * and reuses it across attempts, so retrying never double-charges.
 *
 * Behaviour is a straight port of the Node SDK's ``retry.ts``: same defaults,
 * same backoff formula, and the same 429/``Retry-After`` and
 * ``409 idempotency_in_progress`` decisions.
 */
final class RetryPolicy
{
    /**
     * The one 409 error code that is transient rather than caller-fault.
     *
     * The server returns it when a request carrying the *same*
     * ``Idempotency-Key`` is still in flight ("Retry after a short delay",
     * ``Retry-After: 1``). It is the only 4xx where doing nothing is the
     * dangerous option: the call may well have charged the customer, the
     * caller cannot see the outcome, and the obvious workaround — retry with
     * a *fresh* key — is precisely what turns one charge into two.
     *
     * Retrying is safe because the transport reuses the original
     * ``Idempotency-Key`` on every attempt, so the retry either loses the
     * race again or replays the first call's recorded response.
     */
    public const IN_PROGRESS_CODE = 'idempotency_in_progress';

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

    /**
     * ``$errorCode`` is the envelope's ``error.code``, and is consulted for
     * 409s only; every other decision is status-driven.
     */
    public function shouldRetry(
        ?int $status,
        int $attempt,
        ?float $retryAfterMs = null,
        ?string $errorCode = null,
    ): bool {
        if ($attempt >= $this->maxAttempts) {
            return false;
        }
        if ($status === null) {
            return true; // connection error
        }
        if ($status === 409) {
            // A 409 from a *different* code (``idempotency_key_in_use``, a
            // conflicting subscription state) is a genuine caller-fault
            // conflict that retrying can only repeat, so it still fails fast.
            return $errorCode === self::IN_PROGRESS_CODE;
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
