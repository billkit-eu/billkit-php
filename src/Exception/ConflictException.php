<?php

declare(strict_types=1);

namespace BillKit\Exception;

/** 409: state conflict, including Idempotency-Key replays. */
class ConflictException extends BillKitException
{
}
