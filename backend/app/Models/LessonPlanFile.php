<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tenant-owned. A file attached to a lesson plan; private (`local` disk). */
#[Fillable(['lesson_plan_id', 'file_path', 'file_name', 'mime', 'size', 'uploaded_by'])]
class LessonPlanFile extends Model
{
    protected $connection = 'tenant';

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    /** @return BelongsTo<LessonPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(LessonPlan::class, 'lesson_plan_id');
    }
}
