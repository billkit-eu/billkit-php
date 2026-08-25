<?php

declare(strict_types=1);

namespace BillKit\Exception;

/**
 * Raised by {@see \BillKit\Webhooks::verifySignature()} when a webhook
 * cannot be trusted: missing/malformed header, timestamp outside the
 * tolerance window, signature mismatch, or a body that is not JSON.
 *
 * Deliberately NOT a {@see BillKitException}: it is a local
 * verification failure, not an API response, so callers that blanket
 * ``catch (BillKitException)`` around API calls do not accidentally
 * swallow a webhook forgery signal.
 */
class WebhookVerificationException extends \RuntimeException
{
}
