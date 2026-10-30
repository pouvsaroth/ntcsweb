<?php

declare(strict_types=1);

namespace App\Platform\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in a ticket's thread: a reply (text and/or files) from the
 * customer or NewTech, or a status change (`event`).
 */
class TicketMessage extends Model
{
    public const FROM_USER = 'user';

    public const FROM_STAFF = 'staff';

    public const UPDATED_AT = null;

    protected $table = 'bo_ticket_messages';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'attachments' => 'array',
            'event' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function getConnectionName(): ?string
    {
        return config('tenancy.database.central_connection');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(PlatformStaff::class);
    }
}
