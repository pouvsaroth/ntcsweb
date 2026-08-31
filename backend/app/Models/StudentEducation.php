<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. One prior school a student attended before enrolling here —
 * see the migration for the legacy table this mirrors.
 *
 * @property int $tenant_id
 * @property int $student_id
 */
#[Fillable(['student_id', 'school_name', 'address', 'start_date', 'end_date', 'skill', 'detail'])]
class StudentEducation extends Model
{
    use BelongsToTenant;

    // Eloquent's pluralizer treats "Education" as uncountable (like
    // "advice"/"information") and leaves it singular, guessing
    // `student_education` — doesn't match the migration's
    // `student_educations` unless stated explicitly here.
    protected $table = 'student_educations';

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
