<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A student's score (0–100) for one approved ExamApplication. Scores are
 * entered as a batch from the Grades tab, so — like AttendanceRecord — this
 * model isn't Auditable; ExamScoreService writes one summarizing audit entry
 * per save instead of one per student.
 *
 * @property int $exam_application_id
 * @property int|null $academic_year_id
 * @property string $score
 */
#[Fillable(['exam_application_id', 'academic_year_id', 'score', 'remark', 'recorded_by', 'recorded_at'])]
class ExamScore extends Model
{
    protected $connection = 'tenant';

    protected function casts(): array
    {
        return [
            'score' => 'decimal:2',
            'recorded_at' => 'datetime',
        ];
    }

    public function examApplication(): BelongsTo
    {
        return $this->belongsTo(ExamApplication::class);
    }

    /** The academic year the score was first entered in — see ExamScoreService::record(). */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
