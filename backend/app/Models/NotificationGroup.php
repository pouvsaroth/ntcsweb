<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A school's group of users whose bell notifications also go to one
 * Telegram group (docs/backoffice/stage-11-telegram-notifications.md).
 * Central table (next to `users`); always queried by tenant_id.
 */
class NotificationGroup extends Model
{
    protected $table = 'notification_groups';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'linked_at' => 'datetime',
            'link_code_expires_at' => 'datetime',
        ];
    }

    public function getConnectionName(): ?string
    {
        return config('tenancy.database.central_connection');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'notification_group_user');
    }

    public function isLinked(): bool
    {
        return filled($this->telegram_chat_id);
    }
}
