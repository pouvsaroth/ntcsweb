<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. One contact made with an inquirer (a call, a message, a
 * visit), what was said, and when to follow up next.
 */
#[Fillable(['inquiry_id', 'followed_up_on', 'method', 'note', 'next_follow_up_on', 'created_by', 'created_by_name'])]
class InquiryFollowUp extends Model
{
    public const METHODS = ['call', 'message', 'visit', 'other'];

    protected $connection = 'tenant';

    protected function casts(): array
    {
        return [
            'followed_up_on' => 'date',
            'next_follow_up_on' => 'date',
        ];
    }

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class);
    }
}
