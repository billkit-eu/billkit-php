<?php

declare(strict_types=1);

namespace BillKit\Exception;

/**
 * Base of every exception the SDK throws on purpose.
 *
 * `catch (BillKitException $e)` catches an API error response
 * ({@see ApiException} and its subclasses), a failure to reach the API at
 * all ({@see ApiConnectionException}), and a webhook that failed
 * verification ({@see WebhookVerificationException}).
 *
 * A bad argument that is caught before any request is made (a float where a
 * decimal rate belongs, an unknown request option, a purge without
 * confirmation) is a plain `\InvalidArgumentException` instead: it is a bug
 * in the calling code, not something to handle at runtime.
 */
class BillKitException extends \RuntimeException
{
    public function __construct(string $message = '', ?\Throwable $previous = null)
    {
        // The int `$code` of `\Exception` stays 0. An HTTP status lives on
        // ApiException::$statusCode and the envelope's code on $errorCode.
        parent::__construct($message, 0, $previous);
    }
}
