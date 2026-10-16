<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Customer Portal Inactivity Timeout
    |--------------------------------------------------------------------------
    |
    | Minutes of inactivity after which a customer portal session ends and the
    | customer must scan their QR code again. This is enforced on top of
    | Laravel's normal session (SESSION_LIFETIME), so it only shortens portal
    | sessions; keep it at or below SESSION_LIFETIME. Owner and Super Admin
    | sessions are not affected.
    |
    */

    'idle_minutes' => (int) env('CUSTOMER_PORTAL_IDLE_MINUTES', 15),

    /*
    |--------------------------------------------------------------------------
    | Transaction History Page Size
    |--------------------------------------------------------------------------
    */

    'history_per_page' => 15,

];
