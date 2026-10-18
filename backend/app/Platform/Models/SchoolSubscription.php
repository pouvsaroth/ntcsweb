<?php

declare(strict_types=1);

namespace App\Platform\Models;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One school on one plan for a stretch of time (docs/backoffice/stage-5-billing.md).
 *
 *   trial, active                live — at most one per school (a unique index)
 *   expired                      the end passed unpaid (5e)
 *   cancelled                    staff stopped it
 *   replaced                     staff moved the school to another plan
 *
 * `end_on` is the last day covered, in the platform's zone; null = no end.
 */
class SchoolSubscription extends Model
{
    public const TRIAL = 'trial';

    public const ACTIVE = 'active';

    public const EXPIRED = 'expired';

    public const CANCELLED = 'cancelled';

    public const REPLACED = 'replaced';

    public const LIVE = [self::TRIAL, self::ACTIVE];

    protected $table = 'bo_school_subscriptions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'start_on' => 'date',
            'end_on' => 'date',
            'ended_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // A plan change must reach this request's permission checks too.
        static::saved(fn () => app(\App\Platform\Billing\SchoolModules::class)->forget());
    }

    public function getConnectionName(): ?string
    {
        return config('tenancy.database.central_connection');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(PlatformStaff::class, 'created_by');
    }

    /** @param Builder<self> $query */
    public function scopeLive(Builder $query): void
    {
        $query->whereIn('status', self::LIVE);
    }

    public function isLive(): bool
    {
        return in_array($this->status, self::LIVE, true);
    }

    /** Whole days from today (platform zone) to the end, inclusive of today; null = no end. Negative once past. */
    public function daysLeft(?Carbon $today = null): ?int
    {
        if ($this->end_on === null) {
            return null;
        }
        $today ??= self::today();

        return (int) $today->copy()->startOfDay()->diffInDays($this->end_on->copy()->startOfDay(), false);
    }

    public static function today(): Carbon
    {
        return Carbon::now(config('platform.billing_timezone', 'Asia/Phnom_Penh'))->startOfDay();
    }
}
