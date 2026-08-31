<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Billing\InvoiceStatus;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A student's bill. Deliberately does NOT use the Auditable trait — every
 * write here goes through InvoiceService, which fires its own richer,
 * explicit audit entries (INVOICE_CREATED, INVOICE_CANCELLED, ...) with a
 * financial description that generic column-diffing can't produce. See
 * AuditAction's billing section.
 *
 * subtotal/discount/tax/total/paid_amount/balance are only ever written by
 * InvoiceService/PaymentService, computed from this invoice's own items and
 * payments — never accepted as request input.
 *
 * @property int $tenant_id
 * @property string $invoice_number
 * @property int $student_id
 * @property string $status
 * @property string $total
 * @property string $paid_amount
 * @property string $balance
 */
#[Fillable([
    'invoice_number', 'student_id', 'invoice_date', 'due_date', 'status',
    'subtotal', 'discount', 'tax', 'total', 'paid_amount', 'balance', 'currency', 'notes',
    'created_by', 'cancellation_reason', 'cancelled_by', 'cancelled_at',
])]
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use BelongsToTenant, HasFactory;

    protected $attributes = [
        'status' => InvoiceStatus::DRAFT,
        'subtotal' => 0,
        'discount' => 0,
        'tax' => 0,
        'total' => 0,
        'paid_amount' => 0,
        'balance' => 0,
        'currency' => 'USD',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'due_date' => 'date',
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'tax' => 'decimal:2',
            'total' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'balance' => 'decimal:2',
            'cancelled_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function notificationLogs(): HasMany
    {
        return $this->hasMany(NotificationLog::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function isClosed(): bool
    {
        return InvoiceStatus::isClosed($this->status);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeOutstanding(Builder $query): void
    {
        $query->whereIn('status', [InvoiceStatus::ISSUED, InvoiceStatus::PARTIALLY_PAID, InvoiceStatus::OVERDUE]);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeOverdue(Builder $query): void
    {
        $query->whereIn('status', [InvoiceStatus::ISSUED, InvoiceStatus::PARTIALLY_PAID])
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now()->toDateString());
    }

    public function auditDisplayName(): string
    {
        return $this->invoice_number;
    }
}
