<?php

declare(strict_types=1);

namespace BillKit\Exception;

/**
 * 5xx: BillKit failed. Retried by the SDK before it reaches you; when it
 * does, the retry budget is spent.
 */
class ServerException extends ApiException
{
}
