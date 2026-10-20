<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Late payment penalty limits
    |--------------------------------------------------------------------------
    |
    | Owners configure their store's fee and grace period within these
    | approved limits. Defaults are used when an owner first opens the
    | penalty settings. Review the policy against applicable law before
    | enabling it for real customers.
    |
    */

    'default_fee_cents' => 1000,

    'default_grace_days' => 3,

    'max_fee_cents' => (int) env('PENALTY_MAX_FEE_CENTS', 10000),

    'max_grace_days' => 30,

];
