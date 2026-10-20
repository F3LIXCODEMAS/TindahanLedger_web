<?php

namespace App\Exceptions;

use RuntimeException;

class StoreSuspended extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This store is suspended. Credits and payments cannot be recorded until it is reactivated.');
    }
}
