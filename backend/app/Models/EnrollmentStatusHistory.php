<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\EnrollmentStatusHistoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per status transition on an Enrollment — see the migration's
 * docblock. Written exclusively by EnrollmentService::changeStatus(), never
 * updated or deleted afterward.
 *
 * @property int $tenant_id
 * @property int $enrollment_id
 * @property string $from_status
 * @property string $to_status
 */
#[Fillable(['enrollment_id', 'from_status', 'to_status', 'reason', 'effective_date', 'changed_by'])]
class EnrollmentStatusHistory extends Model
{
    use BelongsToTenant, HasFactory;

    /** @use HasFactory<EnrollmentStatusHistoryFactory> */
    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
        ];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
