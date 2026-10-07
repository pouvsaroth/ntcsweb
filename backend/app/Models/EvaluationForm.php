<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\EvaluationFormFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant-owned. The questions a review asks (HRM > Performance Management >
 * Evaluation forms) — in sections, each rated 1–5 or answered in words —
 * answered by the staff member (self-assessment) and their manager.
 */
#[Fillable(['name', 'description', 'is_active'])]
class EvaluationForm extends Model
{
    /** @use HasFactory<EvaluationFormFactory> */
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

    public function questions(): HasMany
    {
        return $this->hasMany(EvaluationFormQuestion::class)->orderBy('sort_order')->orderBy('id');
    }

    public function cycles(): HasMany
    {
        return $this->hasMany(PerformanceCycle::class);
    }

    public function auditModule(): string
    {
        return 'Performance';
    }
}
