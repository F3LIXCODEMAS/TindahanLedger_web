<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Three-Cycle Reward
    |--------------------------------------------------------------------------
    |
    | When a customer completes the required number of consecutive debt cycles
    | (fully paying their balance each time), they receive a bonus based on
    | the cash they repaid during those cycles: one bonus point for every this
    | many pesos. Changing the value affects future rewards only; rewards
    | already given are stored with their point amount.
    |
    | This is separate from the regular repayment points (one point per
    | ₱100 repaid, SukiPoint::CENTS_PER_POINT), which are always earned.
    |
    */

    'cycle_reward_pesos_per_point' => (int) env('SUKI_CYCLE_REWARD_PESOS_PER_POINT', 100),

];
