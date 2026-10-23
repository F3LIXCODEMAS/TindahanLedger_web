<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A cash purchase could not be saved, for example because of an
 * unavailable product, a missing price, or a total that changed since the
 * owner reviewed it. Nothing is recorded. `$field` names the form field the
 * message belongs to.
 */
class CashPurchaseRejected extends RuntimeException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}
