<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\MarketingCampaignFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant-owned. Marketing > Campaigns: one ad, flyer, event ... `code`
 * (C00001) is given from the id on create; `channel` is a MARKETING_CHANNEL
 * Base Data code. Leads are the inquiries that picked it; enrolled are those
 * that became students.
 */
#[Fillable(['name', 'channel', 'start_on', 'end_on', 'currency', 'budget', 'spent', 'academic_program_id', 'target', 'status', 'description', 'created_by'])]
class MarketingCampaign extends Model
{
    /** @use HasFactory<MarketingCampaignFactory> */
    use Auditable, HasFactory;

    /** The Base Data category `channel` takes its codes from. */
    public const CHANNEL_CATEGORY = 'MARKETING_CHANNEL';

    public const STATUSES = ['planned', 'active', 'completed', 'cancelled'];

    protected $connection = 'tenant';

    protected $attributes = [
        'status' => 'planned',
        'currency' => 'USD',
    ];

    protected function casts(): array
    {
        return [
            'start_on' => 'date',
            'end_on' => 'date',
            'budget' => 'decimal:2',
            'spent' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (MarketingCampaign $campaign) {
            if ($campaign->code === null) {
                $campaign->forceFill(['code' => sprintf('C%05d', $campaign->id)])->saveQuietly();
            }
        });
    }

    /** @return HasMany<Inquiry, $this> */
    public function inquiries(): HasMany
    {
        return $this->hasMany(Inquiry::class);
    }

    /** @return BelongsTo<AcademicProgram, $this> */
    public function program(): BelongsTo
    {
        return $this->belongsTo(AcademicProgram::class, 'academic_program_id')->withTrashed();
    }

    public function auditModule(): string
    {
        return 'Marketing';
    }

    public function auditDisplayName(): string
    {
        return $this->name;
    }
}
