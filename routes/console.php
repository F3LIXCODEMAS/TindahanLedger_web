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

Schedule::command('penalties:apply-due')
    ->dailyAt('00:30')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping(60)
    ->onOneServer()
    ->description('Apply late payment fees to credits past their grace period');
