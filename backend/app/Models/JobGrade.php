<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\JobGradeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant-owned. A pay/seniority grade (G1, G2, ...) a staff member is placed on. One of HRM > Organization Management's lists — see
 * OrganizationUnitController for the CRUD shared with its siblings.
 */
#[Fillable(['code', 'name', 'description', 'is_active'])]
class JobGrade extends Model
{
    /** @use HasFactory<JobGradeFactory> */
    use Auditable, HasFactory;

    protected $connection = 'tenant';

    protected $attributes = [
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function staff(): HasMany
    {
        return $this->hasMany(Staff::class);
    }

    public function auditModule(): string
    {
        return 'Organization';
    }
}
