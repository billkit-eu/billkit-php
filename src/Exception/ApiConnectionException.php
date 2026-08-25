<?php

declare(strict_types=1);

namespace BillKit\Exception;

/** Network-level failure reaching the API (DNS, TLS, timeout, reset). */
class ApiConnectionException extends BillKitException
{
}
