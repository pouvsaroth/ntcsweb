<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Tenant-owned. One period in which a staff member is responsible for a
 * student in a class. See EnrollmentController::assignResponsibleStaff().
 *
 * @property int $enrollment_id
 * @property int $staff_id
 * @property Carbon $from_date
 * @property Carbon|null $to_date
 * @property int|null $assigned_by
 */
#[Fillable(['enrollment_id', 'staff_id', 'from_date', 'to_date', 'assigned_by'])]
class EnrollmentResponsibleStaff extends Model
{
    protected $connection = 'tenant';

    protected $table = 'enrollment_responsible_staff';

    protected function casts(): array
    {
        return ['from_date' => 'date', 'to_date' => 'date'];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class)->withTrashed();
    }
}
