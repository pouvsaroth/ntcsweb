<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. What a student needs to go up a grade: a minimum average
 * score (%), a minimum attendance (%) and at most so many failed subjects
 * (below `pass_mark`). Each check is optional. No grade level = the school's
 * default rule. See docs/academics/school-setup.md.
 *
 * @property int|null $grade_level_id
 */
#[Fillable(['grade_level_id', 'min_average', 'min_attendance', 'max_failed_subjects', 'pass_mark', 'notes'])]
class PromotionRule extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected function casts(): array
    {
        return [
            'min_average' => 'decimal:2',
            'min_attendance' => 'decimal:2',
            'max_failed_subjects' => 'integer',
            'pass_mark' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<GradeLevel, $this> */
    public function gradeLevel(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class);
    }

    /** The grade's own rule, else the school's default, else none. */
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

    public function auditDisplayName(): string
    {
        return 'Promotion rule';
    }
}
