<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\AssetHistoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The complete business-lifecycle narrative of one asset — "what happened
 * to it" (assigned, transferred, repaired, retired...), in plain language,
 * one row per event, never edited or deleted. Deliberately separate from
 * `audit_logs`, which answers a different question ("who did it in the
 * system") — see AuditAction's Assets section for the parallel entries
 * every lifecycle method also fires there. Written exclusively by
 * AssetHistoryRecorder — nothing else should insert here directly.
 */
#[Fillable(['asset_id', 'event_type', 'description', 'old_value', 'new_value', 'occurred_at', 'actor_id'])]
class AssetHistory extends Model
{
    /** @use HasFactory<AssetHistoryFactory> */
    use BelongsToTenant, HasFactory;

    /** "history" pluralizes to "histories" by Eloquent's default guess, but the migration deliberately names the table `asset_history` (singular — one narrative per asset, not a collection of "asset historys/histories" in the usual sense). */
    protected $table = 'asset_history';

    protected function casts(): array
    {
        return [
            'old_value' => 'array',
            'new_value' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
