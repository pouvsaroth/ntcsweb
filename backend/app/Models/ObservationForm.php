<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant-owned. A classroom observation form: criteria rated 1–5 stars
 * (HRM > Professional Development > Classroom observation).
 * See docs/hrm/professional-development.md.
 */
#[Fillable(['name', 'description', 'is_active'])]
class ObservationForm extends Model
{
    use Auditable;

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

    /** @return HasMany<ObservationFormCriterion, $this> */
    public function criteria(): HasMany
    {
        return $this->hasMany(ObservationFormCriterion::class)->orderBy('sort_order')->orderBy('id');
    }

    public function auditModule(): string
    {
        return 'HRM';
    }

    public function auditDisplayName(): string
    {
        return (string) $this->name;
    }
}
