<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Billing\NotificationStatus;
use Database\Factories\NotificationLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per send *attempt* — resending an invoice creates a new row
 * rather than overwriting the last one, so the full history of every
 * attempt (including past failures) is always visible. Written by
 * InvoiceNotificationService only.
 *
 * @property int $tenant_id
 * @property string $channel
 * @property string $status
 */
#[Fillable([
    'invoice_id', 'student_id', 'channel', 'recipient', 'type', 'status',
    'message', 'provider_message_id', 'error_message', 'sent_at', 'sent_by',
])]
class NotificationLog extends Model
{
    /** @use HasFactory<NotificationLogFactory> */
    use BelongsToTenant, HasFactory;

    protected $attributes = [
        'status' => NotificationStatus::PENDING,
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
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

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
