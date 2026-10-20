<?php

declare(strict_types=1);

namespace App\Platform\Models;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One customer staff user's support chat with NewTech (docs/backoffice/stage-10-support-chat.md).
 */
class Chat extends Model
{
    public const OPEN = 'open';

    public const CLOSED = 'closed';

    protected $table = 'bo_chats';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'waiting' => 'boolean',
            'unread_staff' => 'integer',
            'unread_user' => 'integer',
            'last_message_at' => 'datetime',
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

    public function lastStaff(): BelongsTo
    {
        return $this->belongsTo(PlatformStaff::class, 'last_staff_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    /** Where its files live on the private disk. */
    public function folder(): string
    {
        return 'chats/'.$this->getKey();
    }
}
