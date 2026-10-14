<?php

declare(strict_types=1);

namespace BillKit\Exception;

/**
 * 402: a charge was refused, or the account is suspended. `errorCode` says
 * which:
 *
 * - `payment_refused`: the payment provider declined the charge (a refund it
 *   would not make, say); the message carries the provider's reason.
 * - `tenant_suspended`: the BillKit account behind the key is suspended and
 *   the call would move money.
 *
 * Neither is retried: the same request gets the same answer until something
 * outside it changes.
 */
class PaymentException extends ApiException
{
}
