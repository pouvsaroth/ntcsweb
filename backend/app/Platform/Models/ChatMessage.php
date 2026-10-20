<?php

declare(strict_types=1);

namespace App\Platform\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One message in a support chat: text and/or attachments, from the customer or from NewTech. */
class ChatMessage extends Model
{
    public const FROM_USER = 'user';

    public const FROM_STAFF = 'staff';

    public const UPDATED_AT = null;

    protected $table = 'bo_chat_messages';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'attachments' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function getConnectionName(): ?string
    {
        return config('tenancy.database.central_connection');
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(PlatformStaff::class);
    }
}
