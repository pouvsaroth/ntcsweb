<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Authorization\Permissions;

/**
 * Read-only on purpose — audit logs are historical records. There is
 * deliberately no create/update/delete ability here: nothing in the
 * application writes one through Eloquent (see AuditLogger's own docblock),
 * and nothing should ever be able to edit or remove one through the API.
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
}
