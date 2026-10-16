<?php

declare(strict_types=1);

namespace App\Platform\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** The platform's one backup schedule (row id 1). */
class BackupSchedule extends Model
{
    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    protected $table = 'bo_backup_schedule';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'days' => 'array',
            'keep_days' => 'integer',
            'last_run_at' => 'datetime',
        ];
    }

    public function getConnectionName(): ?string
    {
        return config('tenancy.database.central_connection');
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate(['id' => 1], [
            'enabled' => true,
            'days' => self::DAYS,
            'at' => '02:00',
            'keep_days' => 14,
        ]);
    }

    /** Today's slot, in the platform's zone, when today is a scheduled day; otherwise null. */
    public function slotOn(Carbon $now): ?Carbon
    {
        $local = $now->copy()->setTimezone(config('platform.backup_timezone'));

        if (! $this->enabled || ! in_array(strtolower($local->format('D')), $this->days ?? [], true)) {
            return null;
        }

        [$hour, $minute] = array_map('intval', explode(':', $this->at));

        return $local->copy()->setTime($hour, $minute);
    }

    /** Due when today's slot has passed and no scheduled run has started since it. */
    public function isDue(Carbon $now): bool
    {
        $slot = $this->slotOn($now);

        return $slot !== null
            && $now->greaterThanOrEqualTo($slot)
            && ($this->last_run_at === null || $this->last_run_at->lessThan($slot));
    }

    /** The next time a scheduled run will start, for the screen. */
    public function nextRun(Carbon $now): ?Carbon
    {
        if ($this->isDue($now)) {
            return $now->copy();
        }

        for ($d = 0; $d <= 7; $d++) {
            $slot = $this->slotOn($now->copy()->addDays($d));
            if ($slot !== null && $slot->greaterThan($now)) {
                return $slot;
            }
        }

        return null;
    }
}
