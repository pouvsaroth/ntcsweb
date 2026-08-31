<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Billing\PaymentStatus;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single payment against an Invoice. Does NOT use the Auditable trait —
 * see Invoice's docblock for the same reasoning; PaymentService fires its
 * own explicit PAYMENT_CREATED/PAYMENT_CANCELLED/PAYMENT_REFUNDED entries.
 *
 * `payment_number` doubles as the receipt number — see the migration's
 * docblock for why there is no separate `receipts` table.
 *
 * @property int $tenant_id
 * @property string $payment_number
 * @property int $invoice_id
 * @property int $student_id
 * @property string $amount
 * @property string $status
 */
#[Fillable([
    'payment_number', 'invoice_id', 'student_id', 'amount', 'payment_method',
    'status', 'payment_date', 'reference_number', 'received_by', 'notes',
    'cancellation_reason', 'cancelled_by', 'cancelled_at',
])]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use BelongsToTenant, HasFactory;

    protected $attributes = [
        'status' => PaymentStatus::COMPLETED,
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payment_date' => 'date',
            'cancelled_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function isCounted(): bool
    {
        return $this->status === PaymentStatus::COMPLETED;
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeCompleted(Builder $query): void
    {
        $query->where('status', PaymentStatus::COMPLETED);
    }

    public function auditDisplayName(): string
    {
        return $this->payment_number;
    }
}
