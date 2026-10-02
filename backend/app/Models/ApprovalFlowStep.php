<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Approvals\ApprovalFlow;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step of an item's approval flow — see the migration's docblock and
 * App\Services\Approvals\ApprovalFlow.
 *
 * @property string $document_type
 * @property int $step_order 1-based
 * @property int $approval_group_id
 */
#[Fillable(['document_type', 'step_order', 'approval_group_id'])]
class ApprovalFlowStep extends Model
{
    protected $connection = 'tenant';

    protected static function booted(): void
    {
        static::saved(fn () => ApprovalFlow::invalidate());
        static::deleted(fn () => ApprovalFlow::invalidate());
    }

    protected function casts(): array
    {
        return [
            'step_order' => 'integer',
            'approval_group_id' => 'integer',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ApprovalGroup::class, 'approval_group_id');
    }
}
