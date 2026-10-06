<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\ApplicantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * Tenant-owned. Someone applying to work here (HRM > Recruitment >
 * Applicant management) — see the migration's docblock. `stage` is where
 * they are in the hiring process, which the later Recruitment tabs
 * (interviews, selection, offer, pipeline) move along.
 *
 * @property string $stage
 */
#[Fillable([
    'job_position_id', 'first_name', 'last_name', 'gender', 'date_of_birth', 'phone', 'email', 'address',
    'source', 'expected_salary', 'available_from', 'cover_letter', 'stage', 'notes',
])]
class Applicant extends Model
{
    /** @use HasFactory<ApplicantFactory> */
    use Auditable, HasFactory;

    public const STAGE_NEW = 'new';

    public const STAGES = ['new', 'screening', 'shortlisted', 'interview', 'offer', 'hired', 'rejected', 'withdrawn'];

    public const SOURCE_WEBSITE = 'website';

    public const SOURCES = ['website', 'facebook', 'telegram', 'linkedin', 'job_board', 'referral', 'walk_in', 'other'];

    protected $connection = 'tenant';

    protected $attributes = [
        'stage' => self::STAGE_NEW,
    ];

    protected function casts(): array
    {
        return [
            'job_position_id' => 'integer',
            'date_of_birth' => 'date',
            'available_from' => 'date',
            'expected_salary' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        // The rows go with the FK cascade; their files have to be removed here.
        static::deleting(function (self $applicant) {
            $applicant->documents()->pluck('file_path')->each(fn (string $path) => Storage::disk('local')->delete($path));
        });
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /** Digits only — how a repeat application to the same job is recognised. */
    public static function normalizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    public function jobPosition(): BelongsTo
    {
        return $this->belongsTo(JobPosition::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ApplicantDocument::class);
    }

    public function auditModule(): string
    {
        return 'Recruitment';
    }

    public function auditDisplayName(): string
    {
        return $this->fullName();
    }
}
