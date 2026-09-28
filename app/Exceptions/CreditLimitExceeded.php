<?php

namespace App\Exceptions;

use RuntimeException;

class CreditLimitExceeded extends RuntimeException
{
    public function __construct(string $creditLimit, string $projectedBalance)
    {
        parent::__construct(sprintf(
            'This credit entry would exceed the customer credit limit of %s. Projected balance: %s.',
            $creditLimit,
            $projectedBalance,
        ));
    }
}
