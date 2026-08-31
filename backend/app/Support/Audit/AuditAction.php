<?php

declare(strict_types=1);

namespace App\Support\Audit;

/**
 * The audit action catalog — mirrors Permissions' own "constants, not
 * scattered strings" convention. Kept to what the application actually
 * emits; add a new one only when a real call site needs it.
 */
final class AuditAction
{
    public const CREATE = 'CREATE';

    public const UPDATE = 'UPDATE';

    public const DELETE = 'DELETE';

    public const RESTORE = 'RESTORE';

    public const LOGIN = 'LOGIN';

    public const LOGIN_FAILED = 'LOGIN_FAILED';

    public const LOGIN_BLOCKED = 'LOGIN_BLOCKED';

    public const LOGOUT = 'LOGOUT';

    public const PASSWORD_CHANGE = 'PASSWORD_CHANGE';

    public const PASSWORD_RESET_REQUESTED = 'PASSWORD_RESET_REQUESTED';

    public const EMAIL_VERIFIED = 'EMAIL_VERIFIED';

    public const ROLE_CHANGE = 'ROLE_CHANGE';

    public const STATUS_CHANGE = 'STATUS_CHANGE';

    public const POSITION_CHANGE = 'POSITION_CHANGE';
}
