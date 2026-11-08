<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tenant-owned. One use of a discount code by a student, on an invoice. A cancel releases it. */
#[Fillable(['discount_code_id', 'student_id', 'invoice_id', 'enrollment_id', 'amount', 'currency', 'status', 'used_by'])]
class DiscountCodeRedemption extends Model
{
    public const STATUS_APPLIED = 'applied';

    public const STATUS_RELEASED = 'released';

    protected $connection = 'tenant';

    protected $attributes = [
        'status' => self::STATUS_APPLIED,
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function discountCode(): BelongsTo
    {
        return $this->belongsTo(DiscountCode::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
