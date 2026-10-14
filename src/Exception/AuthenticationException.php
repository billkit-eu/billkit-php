<?php

declare(strict_types=1);

namespace BillKit\Exception;

/**
 * 401: the API key is missing, malformed or revoked.
 */
class AuthenticationException extends ApiException
{
}
