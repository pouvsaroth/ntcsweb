<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\JobPostingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. One advert of a JobPosition on one channel (HRM >
 * Recruitment > Job postings). See JobPosition::scopeOnCareersPage() for
 * how a Website posting puts its job on the public Careers page.
 */
#[Fillable(['job_position_id', 'channel', 'url', 'posted_on', 'expires_on', 'is_active', 'note'])]
class JobPosting extends Model
{
    /** @use HasFactory<JobPostingFactory> */
    use Auditable, HasFactory;

    public const CHANNEL_WEBSITE = 'website';

    public const CHANNELS = [self::CHANNEL_WEBSITE, 'facebook', 'telegram', 'linkedin', 'job_board', 'other'];

    protected $connection = 'tenant';

    protected $attributes = [
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'job_position_id' => 'integer',
            'posted_on' => 'date',
            'expires_on' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function jobPosition(): BelongsTo
    {
        return $this->belongsTo(JobPosition::class);
    }

    public function auditModule(): string
    {
        return 'Recruitment';
    }

    public function auditDisplayName(): string
    {
        return "{$this->channel}: {$this->jobPosition?->title}";
    }
}
