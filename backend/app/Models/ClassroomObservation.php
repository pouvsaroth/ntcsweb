<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant-owned. A teacher observed teaching a class and subject, rated on a
 * form's criteria (copied into classroom_observation_ratings), with
 * strengths, areas to improve and next steps; the teacher acknowledges it.
 * Not counted in the Performance score. See docs/hrm/professional-development.md.
 */
#[Fillable(['staff_id', 'observer_staff_id', 'class_id', 'book_id', 'observation_form_id', 'form_name', 'observed_on', 'start_time', 'end_time', 'focus', 'status', 'strengths', 'improvements', 'next_steps', 'average_rating', 'completed_at', 'acknowledged_at', 'teacher_comment', 'created_by'])]
class ClassroomObservation extends Model
{
    use Auditable;

    public const STATUSES = ['scheduled', 'completed', 'cancelled'];

    protected $connection = 'tenant';

    protected $attributes = [
        'status' => 'scheduled',
    ];

    protected function casts(): array
    {
        return [
            'observed_on' => 'date',
            'average_rating' => 'decimal:2',
            'completed_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Staff, $this> */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'staff_id')->withTrashed();
    }

    /** @return BelongsTo<Staff, $this> */
    public function observer(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'observer_staff_id')->withTrashed();
    }

    /** @return BelongsTo<SchoolClass, $this> */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    /** @return BelongsTo<Book, $this> */
    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    /** @return HasMany<ClassroomObservationRating, $this> */
    public function ratings(): HasMany
    {
        return $this->hasMany(ClassroomObservationRating::class)->orderBy('sort_order')->orderBy('id');
    }

    public function auditModule(): string
    {
        return 'HRM';
    }
}
