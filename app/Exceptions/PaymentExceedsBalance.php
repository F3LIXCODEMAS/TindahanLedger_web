<?php

namespace App\Exceptions;

use RuntimeException;

class PaymentExceedsBalance extends RuntimeException
{
    public function __construct(string $remainingBalance, string $paymentAmount)
    {
        parent::__construct(sprintf(
            'Payment cannot exceed the customer outstanding balance of ₱%s.',
            $remainingBalance,
        ));
    }
}
