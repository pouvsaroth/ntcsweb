<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One in-app notification for one user — the bell icon in the admin header.
 * See the migration's own docblock for why this stores `type`/`data` rather
 * than pre-rendered text, and NotificationService for the one place these
 * are ever created.
 *
 * @property int $recipient_id
 * @property string $type
 * @property array $data
 */
#[Fillable(['recipient_id', 'type', 'data', 'link', 'read_at'])]
class UserNotification extends Model
{
    protected $connection = 'tenant';

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
        ];
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeUnread(Builder $query): void
    {
        $query->whereNull('read_at');
    }

    public function markRead(): void
    {
        if ($this->read_at === null) {
            $this->update(['read_at' => now()]);
        }
    }
}
