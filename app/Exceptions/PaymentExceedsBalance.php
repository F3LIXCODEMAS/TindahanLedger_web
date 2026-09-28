<?php

namespace App\Exceptions;

use RuntimeException;

class PaymentExceedsBalance extends RuntimeException
{
    public function __construct(string $remainingBalance, string $paymentAmount)
    {
        parent::__construct(sprintf(
            'The payment of %s exceeds the ledger entry balance of %s.',
            $paymentAmount,
            $remainingBalance,
        ));
    }
}
