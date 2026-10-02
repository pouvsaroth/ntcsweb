<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One approved step of one request — see the migration's docblock and
 * App\Services\Approvals\ApprovalFlow. `approvable_type` is a DocumentType
 * string.
 *
 * @property string $approvable_type
 * @property int $approvable_id
 * @property int $step_order
 * @property int $approval_group_id
 * @property int $user_id
 */
#[Fillable(['approvable_type', 'approvable_id', 'step_order', 'approval_group_id', 'user_id', 'approved_at'])]
class ApprovalStepApproval extends Model
{
    protected $connection = 'tenant';

    protected function casts(): array
    {
        return [
            'approvable_id' => 'integer',
            'step_order' => 'integer',
            'approval_group_id' => 'integer',
            'user_id' => 'integer',
            'approved_at' => 'datetime',
        ];
    }
}
