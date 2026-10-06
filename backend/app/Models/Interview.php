<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\InterviewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Tenant-owned. One interview (a round) with an applicant — HRM >
 * Recruitment > Interview. Interviewers are user ids in
 * interview_interviewers; each may leave one InterviewEvaluation.
 *
 * @property string $status
 */
#[Fillable(['applicant_id', 'round', 'scheduled_at', 'duration_minutes', 'mode', 'location', 'status', 'notes', 'created_by'])]
class Interview extends Model
{
    /** @use HasFactory<InterviewFactory> */
    use Auditable, HasFactory;

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUSES = ['scheduled', 'completed', 'cancelled', 'no_show'];

    public const MODES = ['in_person', 'online', 'phone'];

    protected $connection = 'tenant';

    protected $attributes = [
        'status' => self::STATUS_SCHEDULED,
        'round' => 1,
        'duration_minutes' => 60,
    ];

    protected function casts(): array
    {
        return [
            'applicant_id' => 'integer',
            'round' => 'integer',
            'duration_minutes' => 'integer',
            'scheduled_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }

    public function interviewerRows(): HasMany
    {
        return $this->hasMany(InterviewInterviewer::class);
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(InterviewEvaluation::class);
    }

    /** @return list<int> */
    public function interviewerIds(): array
    {
        return $this->interviewerRows->pluck('user_id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return Collection<int, User> */
    public function interviewers(): Collection
    {
        return User::query()->whereIn('id', $this->interviewerIds())->orderBy('name')->get(['id', 'name']);
    }

    public function isInterviewer(User $user): bool
    {
        return in_array((int) $user->getKey(), $this->interviewerIds(), true);
    }

    /**
     * Interviews this user sits on.
     *
     * @param  Builder<static>  $query
     */
    public function scopeWithInterviewer(Builder $query, User $user): void
    {
        $query->whereHas('interviewerRows', fn (Builder $row) => $row->where('user_id', $user->getKey()));
    }

    public function auditModule(): string
    {
        return 'Recruitment';
    }

    public function auditDisplayName(): string
    {
        return "{$this->applicant?->fullName()}: round {$this->round}";
    }
}
