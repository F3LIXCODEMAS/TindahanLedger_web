<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\Store;
use App\Models\User;

class AdminAuditLogger
{
    /**
     * Record an administrative action. Callers must never pass passwords,
     * tokens, or other secrets in the details.
     *
     * @param  array<string, scalar|null>  $details
     */
    public function record(User $actor, string $action, Store|User $subject, array $details = []): AdminAuditLog
    {
        $auditLog = new AdminAuditLog([
            'action' => $action,
            'subject_type' => $subject instanceof Store ? AdminAuditLog::SUBJECT_STORE : AdminAuditLog::SUBJECT_USER,
            'subject_id' => $subject->getKey(),
            'details' => $details === [] ? null : $details,
        ]);
        $auditLog->forceFill(['actor_user_id' => $actor->getKey()])->save();

        return $auditLog;
    }
}
