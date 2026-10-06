<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\JobPositionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tenant-owned. A vacancy (HRM > Recruitment > Job positions) — usually
 * opened from an approved ManpowerRequest. Advertised through JobPostings;
 * see the migration's docblock for what the public Careers page lists.
 *
 * @property string $status
 */
#[Fillable([
    'manpower_request_id', 'title', 'department_id', 'position_id', 'branch_id', 'headcount', 'employment_type',
    'salary_min', 'salary_max', 'salary_currency', 'description', 'requirements', 'status', 'opened_on', 'closes_on',
])]
class JobPosition extends Model
{
    /** @use HasFactory<JobPositionFactory> */
    use Auditable, HasFactory, SoftDeletes;

    public const STATUS_OPEN = 'open';

    public const STATUS_ON_HOLD = 'on_hold';

    public const STATUS_FILLED = 'filled';

    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [self::STATUS_OPEN, self::STATUS_ON_HOLD, self::STATUS_FILLED, self::STATUS_CLOSED];

    protected $connection = 'tenant';

    protected $attributes = [
        'status' => self::STATUS_OPEN,
        'headcount' => 1,
        'salary_currency' => 'USD',
    ];

    protected function casts(): array
    {
        return [
            'manpower_request_id' => 'integer',
            'department_id' => 'integer',
            'position_id' => 'integer',
            'branch_id' => 'integer',
            'headcount' => 'integer',
            'salary_min' => 'decimal:2',
            'salary_max' => 'decimal:2',
            'opened_on' => 'date',
            'closes_on' => 'date',
        ];
    }

    /** "JP-000007". */
    public function reference(): string
    {
        return 'JP-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function manpowerRequest(): BelongsTo
    {
        return $this->belongsTo(ManpowerRequest::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function postings(): HasMany
    {
        return $this->hasMany(JobPosting::class);
    }

    /**
     * Open, with an active Website posting that has started and not expired
     * — what the public Careers page lists.
     *
     * @param  Builder<static>  $query
     */
    public function scopeOnCareersPage(Builder $query): void
    {
        $today = now()->toDateString();

        $query->where('status', self::STATUS_OPEN)->whereHas('postings', fn (Builder $posting) => $posting
            ->where('channel', JobPosting::CHANNEL_WEBSITE)
            ->where('is_active', true)
            ->whereDate('posted_on', '<=', $today)
            ->where(fn (Builder $expiry) => $expiry->whereNull('expires_on')->orWhereDate('expires_on', '>=', $today)));
    }

    public function auditModule(): string
    {
        return 'Recruitment';
    }

    public function auditDisplayName(): string
    {
        return "{$this->reference()}: {$this->title}";
    }
}
