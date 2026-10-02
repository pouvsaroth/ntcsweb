<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Approvals\ApprovalFlow;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One user's membership of an ApprovalGroup. `user_id` points into the
 * central database, so the User itself is looked up separately (see
 * ApprovalGroupController::withMembers()).
 *
 * @property int $approval_group_id
 * @property int $user_id
 */
#[Fillable(['approval_group_id', 'user_id'])]
class ApprovalGroupMember extends Model
{
    protected $connection = 'tenant';

    protected static function booted(): void
    {
        static::saved(fn () => ApprovalFlow::invalidate());
        static::deleted(fn () => ApprovalFlow::invalidate());
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ApprovalGroup::class, 'approval_group_id');
    }
}
