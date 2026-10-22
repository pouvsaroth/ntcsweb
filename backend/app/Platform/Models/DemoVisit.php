<?php

declare(strict_types=1);

namespace App\Platform\Models;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One "Try the demo" from the website (docs/backoffice/stage-12-demo.md).
 */
class DemoVisit extends Model
{
    public const ROLE_DIRECTOR = 'director';

    public const ROLE_TEACHER = 'teacher';

    public const ROLE_STUDENT = 'student';

    public const ROLES = [self::ROLE_DIRECTOR, self::ROLE_TEACHER, self::ROLE_STUDENT];

    protected $table = 'bo_demo_visits';

    protected $guarded = [];

    protected $hidden = ['ticket_hash'];

    protected function casts(): array
    {
        return [
            'sign_ins' => 'integer',
            'ticket_expires_at' => 'datetime',
            'last_sign_in_at' => 'datetime',
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
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withoutGlobalScopes();
    }

    /** Still usable: not switched off and not past its days. */
    public function isLive(): bool
    {
        return $this->ended_at === null && $this->expires_at->isFuture();
    }
}
