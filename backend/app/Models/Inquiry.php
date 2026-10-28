<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant-owned. Front Office > Inquiry: someone who asked about the school,
 * followed up until they enrol (student_id) or are not interested.
 */
#[Fillable(['inquiry_date', 'name', 'gender', 'phone', 'email', 'interested_in', 'source', 'status', 'next_follow_up_on', 'assigned_staff_id', 'student_id', 'note', 'created_by'])]
class Inquiry extends Model
{
    use Auditable;

    public const SOURCES = ['walk_in', 'phone', 'facebook', 'telegram', 'website', 'referral', 'other'];

    public const STATUSES = ['new', 'following_up', 'enrolled', 'not_interested'];

    /** Still being worked on: shown as due when the next follow-up date comes. */
    public const OPEN_STATUSES = ['new', 'following_up'];

    protected $connection = 'tenant';

    protected $attributes = [
        'status' => 'new',
    ];

    protected function casts(): array
    {
        return [
            'inquiry_date' => 'date',
            'next_follow_up_on' => 'date',
        ];
    }

    public function assignedStaff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'assigned_staff_id')->withTrashed();
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class)->withTrashed();
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(InquiryFollowUp::class)->orderByDesc('followed_up_on')->orderByDesc('id');
    }

    /** Open, with a follow-up date of today or earlier. */
    public function scopeDue(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES)
            ->whereNotNull('next_follow_up_on')
            ->whereDate('next_follow_up_on', '<=', now()->toDateString());
    }

    public function auditModule(): string
    {
        return 'Front Office';
    }
}
