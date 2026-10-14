<?php

declare(strict_types=1);

namespace BillKit\Exception;

/**
 * 403: the key is valid but its scopes do not cover this route.
 */
class PermissionDeniedException extends ApiException
{
}
