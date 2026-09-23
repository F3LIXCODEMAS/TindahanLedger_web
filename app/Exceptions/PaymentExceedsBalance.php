<?php

namespace App\Exceptions;

use RuntimeException;

class PaymentExceedsBalance extends RuntimeException
{
    public function __construct(float $remainingBalance, float $paymentAmount)
    {
        parent::__construct(sprintf(
            'The payment of %.2f exceeds the ledger entry balance of %.2f.',
            $paymentAmount,
            $remainingBalance,
        ));
    }
}
