<?php

declare(strict_types=1);

namespace BillKit\Exception;

/**
 * The request never got an HTTP response: DNS, TLS, a refused or reset
 * connection, or a timeout ({@see ApiTimeoutException}).
 *
 * The SDK retries these before throwing. With an injected PSR-18 client,
 * that client's own exception is on `getPrevious()`; the message has had
 * every URL's query string removed, because list filters carry values like
 * `email=`.
 */
class ApiConnectionException extends BillKitException
{
}
