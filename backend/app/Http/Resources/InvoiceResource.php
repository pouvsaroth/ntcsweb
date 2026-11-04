<?php

declare(strict_types=1);

namespace App\Http\Resources;

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
            // Billing > Invoices > Invoice by class: billed to a company for a class, no student.
            'company_id' => $this->company_id,
            'company' => $this->whenLoaded('company', fn () => $this->company ? ['id' => $this->company->id, 'name' => $this->company->name, 'contact_person' => $this->company->contact_person, 'phone' => $this->company->phone, 'address' => $this->company->address, 'tax_number' => $this->company->tax_number] : null),
            'class_id' => $this->class_id,
            'class' => $this->whenLoaded('schoolClass', fn () => $this->schoolClass ? ['id' => $this->schoolClass->id, 'name' => $this->schoolClass->name] : null),
            'period_from' => $this->period_from?->toDateString(),
            'period_to' => $this->period_to?->toDateString(),
            'students' => $this->whenLoaded('invoiceStudents', fn () => $this->invoiceStudents->map(fn ($row) => [
                'student_id' => $row->student_id,
                'student_code' => $row->student_code,
                'name' => $row->student_name,
            ])->values()),
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
            'course' => $this->when($this->hasCourseRelationsLoaded(), fn () => $this->resource->courseName()),
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
}
