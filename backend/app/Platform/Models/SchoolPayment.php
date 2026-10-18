<?php

declare(strict_types=1);

namespace App\Platform\Models;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money a school paid the platform, recorded by staff (docs/backoffice/stage-5-billing.md, 5b).
 * Not App\Models\Payment, which is a student paying a school.
 */
class SchoolPayment extends Model
{
    public const METHODS = ['cash', 'bank', 'khqr', 'cheque', 'other'];

    protected $table = 'bo_payments';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'periods' => 'integer',
            'paid_on' => 'date',
            'covered_from' => 'date',
            'covered_to' => 'date',
            'end_before' => 'date',
            'voided_at' => 'datetime',
        ];
    }

    public function getConnectionName(): ?string
    {
        return config('tenancy.database.central_connection');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(SchoolSubscription::class, 'subscription_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(PlatformStaff::class, 'recorded_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(PlatformStaff::class, 'voided_by');
    }

    public function isVoid(): bool
    {
        return $this->voided_at !== null;
    }
}
