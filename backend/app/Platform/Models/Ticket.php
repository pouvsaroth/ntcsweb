<?php

declare(strict_types=1);

namespace App\Platform\Models;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A school user's support ticket to NewTech (docs/backoffice/stage-13-help-support.md, 13b).
 */
class Ticket extends Model
{
    public const OPEN = 'open';

    public const IN_PROGRESS = 'in_progress';

    public const RESOLVED = 'resolved';

    public const CLOSED = 'closed';

    public const STATUSES = [self::OPEN, self::IN_PROGRESS, self::RESOLVED, self::CLOSED];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    protected $table = 'bo_tickets';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'unread_staff' => 'integer',
            'unread_user' => 'integer',
            'last_reply_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
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
        return $this->belongsTo(User::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(PlatformStaff::class, 'assigned_staff_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class);
    }

    /** What people quote: T-00042. */
    public function number(): string
    {
        return 'T-'.str_pad((string) $this->getKey(), 5, '0', STR_PAD_LEFT);
    }

    /** Still waiting on NewTech or the customer (not resolved or closed). */
    public function isActive(): bool
    {
        return in_array($this->status, [self::OPEN, self::IN_PROGRESS], true);
    }

    /** Where its files live on the private disk. */
    public function folder(): string
    {
        return 'tickets/'.$this->getKey();
    }
}
