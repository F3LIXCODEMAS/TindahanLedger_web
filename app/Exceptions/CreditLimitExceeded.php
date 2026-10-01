<?php

namespace App\Exceptions;

use RuntimeException;

class CreditLimitExceeded extends RuntimeException
{
    public function __construct(
        public readonly string $currentBalance,
        public readonly string $availableCredit,
        public readonly string $requestedCredit,
        public readonly string $projectedBalance,
    ) {
        parent::__construct(sprintf(
            'Credit limit exceeded. Current balance: ₱%s. Available credit: ₱%s. Requested credit: ₱%s.',
            $currentBalance,
            $availableCredit,
            $requestedCredit,
        ));
    }
}
