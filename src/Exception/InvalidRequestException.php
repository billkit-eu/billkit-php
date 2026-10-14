<?php

declare(strict_types=1);

namespace BillKit\Exception;

/**
 * 400 and every other 4xx without its own class: the request has to
 * change before it can succeed. `param` names the offending field when the
 * API knows it.
 */
class InvalidRequestException extends ApiException
{
}
