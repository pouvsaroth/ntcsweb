<?php

declare(strict_types=1);

namespace App\Platform\Models;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A read-only "view as the school" opened by staff (docs/backoffice/stage-6-support.md, 6b).
 * Open until it is ended or its time is up; a view past its time with no
 * ended_at has timed out.
 */
class SupportSession extends Model
{
    public const ENDED_BY_STAFF = 'staff';

    public const ENDED_BY_TIMEOUT = 'timeout';

    public const ENDED_BY_SIGN_OUT = 'sign_out';

    /** How long the ticket that opens a view works. */
    public const TICKET_SECONDS = 60;

    protected $table = 'bo_support_sessions';

    protected $guarded = [];

    protected $hidden = ['ticket_hash'];

    protected function casts(): array
    {
        return [
            'ticket_expires_at' => 'datetime',
            'ticket_used_at' => 'datetime',
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function getConnectionName(): ?string
    {
        return config('tenancy.database.central_connection');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(PlatformStaff::class, 'staff_id');
    }

    public function endedByStaff(): BelongsTo
    {
        return $this->belongsTo(PlatformStaff::class, 'ended_by_staff_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withoutGlobalScopes();
    }

    public function events(): HasMany
    {
        return $this->hasMany(SupportSessionEvent::class, 'support_session_id');
    }

    public function isOpen(): bool
    {
        return $this->ended_at === null && $this->expires_at->isFuture();
    }

    /** open | ended | timed_out */
    public function state(): string
    {
        return match (true) {
            $this->isOpen() => 'open',
            $this->ended_at === null || $this->ended_by === self::ENDED_BY_TIMEOUT => 'timed_out',
            default => 'ended',
        };
    }
}
