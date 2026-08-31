<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Payment
 */
class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payment_number' => $this->payment_number,
            'invoice_id' => $this->invoice_id,
            'invoice_number' => $this->whenLoaded('invoice', fn () => $this->invoice->invoice_number),
            'student_id' => $this->student_id,
            'amount' => (float) $this->amount,
            'payment_method' => $this->payment_method,
            'status' => $this->status,
            'payment_date' => $this->payment_date?->toDateString(),
            'reference_number' => $this->reference_number,
            'received_by' => $this->whenLoaded('receivedBy', fn () => $this->receivedBy?->name),
            'notes' => $this->notes,
            'cancellation_reason' => $this->cancellation_reason,
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
