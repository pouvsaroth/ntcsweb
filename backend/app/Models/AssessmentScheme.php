<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant-owned. How one grade level is assessed (no grade level = the
 * school's default): `score` — weighted components and letter bands — or
 * `competency` — a level per criterion. See docs/academics/gradebook.md.
 *
 * @property int|null $grade_level_id
 * @property string $mode
 */
#[Fillable(['grade_level_id', 'mode', 'name', 'max_score', 'decimals', 'absent_counts_as_zero', 'notes'])]
class AssessmentScheme extends Model
{
    use Auditable;

    public const MODE_SCORE = 'score';

    public const MODE_COMPETENCY = 'competency';

    public const MODES = [self::MODE_SCORE, self::MODE_COMPETENCY];

    protected $connection = 'tenant';

    protected function casts(): array
    {
        return ['max_score' => 'decimal:2', 'decimals' => 'integer', 'absent_counts_as_zero' => 'boolean'];
    }

    /** @return BelongsTo<GradeLevel, $this> */
    public function gradeLevel(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class);
    }

    /** @return HasMany<AssessmentComponent, $this> */
    public function components(): HasMany
    {
        return $this->hasMany(AssessmentComponent::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<GradeBand, $this> */
    public function bands(): HasMany
    {
        return $this->hasMany(GradeBand::class)->orderByDesc('min_percent');
    }

    /** @return HasMany<CompetencyCriterion, $this> */
    public function criteria(): HasMany
    {
        return $this->hasMany(CompetencyCriterion::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<CompetencyLevel, $this> */
    public function levels(): HasMany
    {
        return $this->hasMany(CompetencyLevel::class)->orderBy('sort_order')->orderByDesc('points');
    }

    /** The grade's own scheme, else the school's default, else none. */
    public static function for(?int $gradeLevelId): ?self
    {
        if ($gradeLevelId !== null) {
            $own = self::query()->where('grade_level_id', $gradeLevelId)->first();
            if ($own !== null) {
                return $own;
            }
        }

        return self::query()->whereNull('grade_level_id')->first();
    }

    public function auditModule(): string
    {
        return 'Academic';
    }
}
