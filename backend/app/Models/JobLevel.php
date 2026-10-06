<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\JobLevelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant-owned. A career level (Junior, Senior, Manager) a staff member holds. One of HRM > Organization Management's lists — see
 * OrganizationUnitController for the CRUD shared with its siblings.
 */
#[Fillable(['code', 'name', 'description', 'is_active'])]
class JobLevel extends Model
{
    /** @use HasFactory<JobLevelFactory> */
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
