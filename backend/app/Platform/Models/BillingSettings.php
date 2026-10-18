<?php

declare(strict_types=1);

namespace App\Platform\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The platform's one billing settings row (id 1): trial, grace, warning days and the receipt header. */
class BillingSettings extends Model
{
    protected $table = 'bo_billing_settings';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'trial_days' => 'integer',
            'grace_days' => 'integer',
            'warning_days' => 'integer',
        ];
    }

    public function getConnectionName(): ?string
    {
        return config('tenancy.database.central_connection');
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate(['id' => 1], ['trial_days' => 15, 'grace_days' => 7, 'warning_days' => 14]);
    }

    /** The receipt logo on the private `local` disk, or null. */
    public function logoPath(): ?string
    {
        if (! $this->company_logo) {
            return null;
        }
        $path = \Illuminate\Support\Facades\Storage::disk('local')->path($this->company_logo);

        return is_file($path) ? $path : null;
    }

    public function trialPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'trial_plan_id');
    }
}
