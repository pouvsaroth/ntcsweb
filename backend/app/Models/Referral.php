<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. Marketing > Referrals: `referrer` brought `referred` (one
 * referrer per student). Pending until the friend's first payment, then
 * rewarded with the referrer's own copy of the reward discount code.
 */
#[Fillable(['referrer_student_id', 'referred_student_id', 'status', 'payment_id', 'reward_discount_code_id', 'rewarded_on'])]
class Referral extends Model
{
    use Auditable;

    public const STATUS_PENDING = 'pending';

    public const STATUS_REWARDED = 'rewarded';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_REWARDED];

    protected $connection = 'tenant';

    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    protected function casts(): array
    {
        return [
            'rewarded_on' => 'date',
        ];
    }

    /** @return BelongsTo<Student, $this> */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'referrer_student_id')->withTrashed();
    }

    /** @return BelongsTo<Student, $this> */
    public function referred(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'referred_student_id')->withTrashed();
    }

    /** @return BelongsTo<DiscountCode, $this> */
    public function rewardCode(): BelongsTo
    {
        return $this->belongsTo(DiscountCode::class, 'reward_discount_code_id');
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function auditModule(): string
    {
        return 'Marketing';
    }
}
