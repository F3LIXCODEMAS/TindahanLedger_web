<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Association rule thresholds (DUMS-39)
    |--------------------------------------------------------------------------
    |
    | Starting values for experiments, not proven optimal settings. Every
    | comparison is inclusive: a rule is kept when its value is greater than
    | or equal to the threshold. Probabilities are decimals from 0 to 1 with
    | at most six decimal places. Set ASSOCIATION_MIN_LIFT=off to disable the
    | lift filter. `php artisan apriori:train` accepts per-run overrides.
    |
    | - min_baskets: eligible baskets a scope needs before any rule is
    |   published; below this the run is recorded as insufficient_data and
    |   the previously active rule set stays active.
    | - min_antecedent_count: baskets that must contain the "if" product.
    | - min_pair_count: baskets that must contain both products.
    |
    */

    'min_support' => env('ASSOCIATION_MIN_SUPPORT', '0.01'),

    'min_confidence' => env('ASSOCIATION_MIN_CONFIDENCE', '0.30'),

    'min_lift' => env('ASSOCIATION_MIN_LIFT', '1.0'),

    'min_baskets' => env('ASSOCIATION_MIN_BASKETS', '100'),

    'min_antecedent_count' => env('ASSOCIATION_MIN_ANTECEDENT_COUNT', '5'),

    'min_pair_count' => env('ASSOCIATION_MIN_PAIR_COUNT', '3'),

    /*
    |--------------------------------------------------------------------------
    | Training lock
    |--------------------------------------------------------------------------
    |
    | One training run per scope at a time. The lock expires on its own after
    | this many seconds, so a crashed run cannot block training forever.
    |
    */

    'lock_seconds' => (int) env('ASSOCIATION_TRAINING_LOCK_SECONDS', 1800),

];
