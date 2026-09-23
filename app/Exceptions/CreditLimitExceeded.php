<?php

namespace App\Exceptions;

use RuntimeException;

class CreditLimitExceeded extends RuntimeException
{
    public function __construct(float $creditLimit, float $projectedBalance)
    {
        parent::__construct(sprintf(
            'This credit entry would exceed the customer credit limit of %.2f. Projected balance: %.2f.',
            $creditLimit,
            $projectedBalance,
        ));
    }
}
