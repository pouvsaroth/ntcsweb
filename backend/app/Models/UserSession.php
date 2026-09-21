<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Auth\AuthService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per currently-open browser (session-transport) login — see the
 * migration that creates this table for why it exists instead of a boolean
 * flag, and {@see AuthService::ensureNoOtherActiveDevice()}
 * for how it's used to enforce per-role concurrent-device limits.
 *
 * Deliberately no `updated_at`: a row is only ever created (login) and
 * deleted (logout/force-logout), never modified in place.
 *
 * @property int $user_id
 * @property string $session_id
 */
#[Fillable(['user_id', 'session_id', 'ip_address', 'user_agent'])]
class UserSession extends Model
{
    protected $table = 'user_login_sessions';

    public const UPDATED_AT = null;

    /**
     * Pinned to the central connection, same as User — see
     * User::getConnectionName()'s docblock for why this can't be left
     * implicit.
     */
    public function getConnectionName(): ?string
    {
        return config('tenancy.database.central_connection');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
