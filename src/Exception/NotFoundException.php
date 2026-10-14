<?php

declare(strict_types=1);

namespace BillKit\Exception;

/**
 * 404: no such object. An id that belongs to another account is a 404 too,
 * on purpose, so probing cannot confirm that it exists.
 */
class NotFoundException extends ApiException
{
}
