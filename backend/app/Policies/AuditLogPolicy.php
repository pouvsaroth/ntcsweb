<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Authorization\Permissions;

/**
 * Audit logs are historical records: nothing writes one through Eloquent
 * (see AuditLogger's own docblock) and no single entry can ever be edited
 * or removed. The one exception is `clear` — bulk-deleting a whole date
 * range to keep the table from growing forever — behind its own permission,
 * and the clear itself is logged.
 */
class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::AUDIT_LOGS_VIEW);
    }

    public function view(User $user, AuditLog $log): bool
    {
        return $user->hasPermission(Permissions::AUDIT_LOGS_VIEW);
    }

    public function clear(User $user): bool
    {
        return $user->hasPermission(Permissions::AUDIT_LOGS_DELETE);
    }
}
