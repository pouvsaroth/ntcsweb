<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. One guardian of one student — see the migration for why
 * this is its own table (a student can have more than one) and why
 * `guardian_type` is free text.
 *
 * @property int $tenant_id
 * @property int $student_id
 */
#[Fillable(['student_id', 'guardian_name', 'guardian_type', 'address', 'phone', 'email', 'remark'])]
class StudentGuardian extends Model
{
    use BelongsToTenant;

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
