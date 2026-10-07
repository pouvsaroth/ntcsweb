<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned, at most one per staff member. What Tax on Salary and NSSF
 * need to know about them: tax resident (a non-resident pays a flat rate),
 * a dependent spouse and children (each lowers taxable pay), and NSSF
 * enrolment. A staff member without one counts as resident, no
 * dependants, enrolled (see forStaff()).
 */
#[Fillable(['staff_id', 'tax_resident', 'spouse_dependent', 'child_dependents', 'social_security_enrolled', 'social_security_number'])]
class StaffPayrollProfile extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $attributes = [
        'tax_resident' => true,
        'spouse_dependent' => false,
        'child_dependents' => 0,
        'social_security_enrolled' => true,
    ];

    protected function casts(): array
    {
        return [
            'tax_resident' => 'boolean',
            'spouse_dependent' => 'boolean',
            'child_dependents' => 'integer',
            'social_security_enrolled' => 'boolean',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    /** Their saved profile, or an unsaved one with the defaults. */
    public static function forStaff(int $staffId): self
    {
        return self::query()->where('staff_id', $staffId)->first() ?? new self(['staff_id' => $staffId]);
    }

    public function auditModule(): string
    {
        return 'Payroll';
    }
}
