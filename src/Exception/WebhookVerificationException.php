<?php

declare(strict_types=1);

namespace BillKit\Exception;

/**
 * Thrown by {@see \BillKit\Webhooks::verifySignature()} when a delivery
 * cannot be trusted: a missing or malformed `BillKit-Signature` header, a
 * timestamp outside the tolerance window, a signature mismatch, or a body
 * that is not a JSON object.
 *
 * Answer it with a 4xx and do not process the body.
 */
class WebhookVerificationException extends BillKitException
{
}
