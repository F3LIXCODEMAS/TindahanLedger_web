<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('reminders:send-due')
    ->dailyAt('08:00')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping(60)
    ->onOneServer()
    ->description('Send scheduled repayment reminders');
