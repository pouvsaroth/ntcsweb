<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\AcademicYearFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A real academic year (e.g. "2026") used across the school.
 *
 * The proof-of-concept for database-per-tenant: lives in the school's own
 * database (see the migration's docblock and TenantProvisioningService) via
 * the `tenant` connection below, rather than BelongsToTenant's shared-table
 * `tenant_id` scoping every other model still uses — see BelongsToTenant's
 * own docblock for why nothing else has moved yet.
 */
#[Fillable(['name', 'start_date', 'end_date', 'is_current'])]
class AcademicYear extends Model
{
    /** @use HasFactory<AcademicYearFactory> */
    use Auditable, HasFactory, SoftDeletes;

    protected $connection = 'tenant';

    protected $attributes = [
        'is_current' => false,
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'is_current' => 'boolean',
        ];
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeCurrent(Builder $query): void
    {
        $query->where('is_current', true);
    }

    public function auditModule(): string
    {
        return 'Academic';
    }

    public function auditDisplayName(): string
    {
        return $this->name;
    }
}
