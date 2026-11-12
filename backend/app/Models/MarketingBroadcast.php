<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. Marketing > Broadcast: one promotion sent to the school's
 * linked Telegram groups. `results` keeps each group's name and whether
 * Telegram took it; status is sent (all), partial (some) or failed (none).
 */
#[Fillable(['title', 'message', 'marketing_campaign_id', 'status', 'results', 'sent_at', 'created_by', 'created_by_name'])]
class MarketingBroadcast extends Model
{
    use Auditable;

    public const STATUSES = ['sent', 'partial', 'failed'];

    protected $connection = 'tenant';

    protected function casts(): array
    {
        return [
            'results' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<MarketingCampaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaign::class, 'marketing_campaign_id');
    }

    public function auditModule(): string
    {
        return 'Marketing';
    }

    public function auditDisplayName(): string
    {
        return $this->title;
    }
}
