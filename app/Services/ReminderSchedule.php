<?php

namespace App\Services;

use App\Models\EmailReminderLog;
use Carbon\CarbonImmutable;

class ReminderSchedule
{
    public function reminderTypeFor(CarbonImmutable $deadline, CarbonImmutable $date): ?string
    {
        $deadlineDate = $deadline->startOfDay();
        $date = $date->setTimezone($deadlineDate->timezone)->startOfDay();

        if ($date->equalTo($deadlineDate->subDays(3))) {
            return EmailReminderLog::TYPE_ADVANCE;
        }

        if ($date->equalTo($deadlineDate)) {
            return EmailReminderLog::TYPE_DUE_DATE;
        }

        $daysOverdue = (int) $deadlineDate->diffInDays($date, false);

        if ($daysOverdue >= 7 && $daysOverdue % 7 === 0) {
            return EmailReminderLog::TYPE_OVERDUE;
        }

        return null;
    }
}
