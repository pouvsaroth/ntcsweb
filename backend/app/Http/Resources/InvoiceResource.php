<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Invoice
 */
class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_number' => $this->invoice_number,
            'student_id' => $this->student_id,
            'student' => $this->whenLoaded('student', fn () => [
                'id' => $this->student->id,
                'student_code' => $this->student->student_code,
                'name' => $this->student->fullName(),
            ]),
            'invoice_date' => $this->invoice_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'status' => $this->status,
            'subtotal' => (float) $this->subtotal,
            'discount' => (float) $this->discount,
            'discount_reason' => $this->discount_reason,
            'tax' => (float) $this->tax,
            'total' => (float) $this->total,
            'paid_amount' => (float) $this->paid_amount,
            'balance' => (float) $this->balance,
            'currency' => $this->currency,
            // Only the list endpoint eager-loads items.reference/items.product
            // for this — see InvoiceController::index().
            'course' => $this->when($this->hasCourseRelationsLoaded(), fn () => $this->courseName()),
            'notes' => $this->notes,
            'payment_type' => $this->payment_type,
            'cancellation_reason' => $this->cancellation_reason,
            'cancelled_by' => $this->whenLoaded('cancelledBy', fn () => $this->cancelledBy?->name),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->name),
            'items' => InvoiceItemResource::collection($this->whenLoaded('items')),
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    private function hasCourseRelationsLoaded(): bool
    {
        return $this->relationLoaded('items')
            && $this->items->every(fn (InvoiceItem $item) => $item->relationLoaded('reference') && $item->relationLoaded('product'));
    }

    /**
     * An enrollment invoice's course package (also covers migrated legacy
     * invoices, whose product is just "Legacy Payment" but whose item still
     * references the enrollment); otherwise the items' own product names,
     * e.g. "Exam Fee".
     */
    private function courseName(): ?string
    {
        $names = $this->items
            ->map(fn (InvoiceItem $item) => $item->reference instanceof Enrollment
                ? $item->reference->coursePackage?->name
                : $item->product?->name)
            ->filter()
            ->unique()
            ->values();

        return $names->isEmpty() ? null : $names->implode(', ');
    }
}
