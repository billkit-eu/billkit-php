<?php

declare(strict_types=1);

namespace BillKit\Exception;

/** 403: the key is valid but not allowed to perform this action. */
class PermissionException extends BillKitException
{
}
