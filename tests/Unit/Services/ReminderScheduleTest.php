<?php

use App\Models\EmailReminderLog;
use App\Services\ReminderSchedule;
use Carbon\CarbonImmutable;

it('selects only the advance, due-date, and seven-day overdue calendar triggers', function (
    string $deadline,
    string $date,
    ?string $expectedType,
) {
    $reminderType = (new ReminderSchedule)->reminderTypeFor(
        CarbonImmutable::parse($deadline, 'Asia/Manila'),
        CarbonImmutable::parse($date, 'Asia/Manila'),
    );

    expect($reminderType)->toBe($expectedType);
})->with([
    'three days before' => ['2026-10-20', '2026-10-17', EmailReminderLog::TYPE_ADVANCE],
    'two days before' => ['2026-10-20', '2026-10-18', null],
    'on the deadline' => ['2026-10-20', '2026-10-20', EmailReminderLog::TYPE_DUE_DATE],
    'six days overdue' => ['2026-10-20', '2026-10-26', null],
    'seven days overdue' => ['2026-10-20', '2026-10-27', EmailReminderLog::TYPE_OVERDUE],
    'thirteen days overdue' => ['2026-10-20', '2026-11-02', null],
    'fourteen days overdue' => ['2026-10-20', '2026-11-03', EmailReminderLog::TYPE_OVERDUE],
    'before the advance window' => ['2026-10-20', '2026-10-14', null],
]);

it('compares calendar dates after converting them to the deadline timezone', function () {
    $reminderType = (new ReminderSchedule)->reminderTypeFor(
        CarbonImmutable::parse('2026-10-21 00:00:00', 'Asia/Manila'),
        CarbonImmutable::parse('2026-10-17 16:30:00', 'UTC'),
    );

    expect($reminderType)->toBe(EmailReminderLog::TYPE_ADVANCE);
});
