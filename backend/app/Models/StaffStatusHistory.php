<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\StaffStatusHistoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per status transition on a Staff member — see the migration's
 * docblock. Written exclusively by StaffController::changeStatus(), never
 * updated or deleted afterward.
 *
 * @property int $staff_id
 * @property string $from_status
 * @property string $to_status
 */
#[Fillable(['staff_id', 'from_status', 'to_status', 'reason', 'requested_date', 'effective_date', 'changed_by'])]
class StaffStatusHistory extends Model
{
    use HasFactory;

    protected $connection = 'tenant';

    /** @use HasFactory<StaffStatusHistoryFactory> */
    protected function casts(): array
    {
        return [
            'requested_date' => 'date',
            'effective_date' => 'date',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
