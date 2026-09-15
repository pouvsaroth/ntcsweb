<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Enrollment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row per enrollment being billed monthly — not one row per invoice.
 * `monthly_invoices_count` is populated by MonthlyInvoiceController's
 * withCount() (how many invoices have been issued for this enrollment so
 * far); `next_payment_date` is computed there too, since it depends on that
 * count and isn't a stored column — see that controller's docblock.
 *
 * @mixin Enrollment
 */
class MonthlyInvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'student' => $this->whenLoaded('student', fn () => [
                'id' => $this->student->id,
                'student_code' => $this->student->student_code,
                'name' => $this->student->fullName(),
            ]),
            'course' => $this->whenLoaded('coursePackage', fn () => $this->coursePackage?->name),
            'class' => $this->whenLoaded('schoolClass', fn () => $this->schoolClass?->name),
            'start_date' => $this->enrolled_at?->toDateString(),
            'end_date' => $this->whenLoaded('schoolClass', fn () => $this->schoolClass?->end_date?->toDateString()),
            'status' => $this->status,
            'monthly_invoices_count' => $this->monthly_invoices_count,
            'next_payment_date' => $this->next_payment_date,
            // Backs the "reprint invoice" action — the most recent monthly
            // invoice actually issued for this enrollment, see
            // MonthlyBillingScheduleService::latestMonthlyInvoice().
            'latest_invoice_id' => $this->whenLoaded('invoiceItems', fn () => $this->invoiceItems->first()?->invoice_id),
            'latest_invoice_number' => $this->whenLoaded('invoiceItems', fn () => $this->invoiceItems->first()?->invoice?->invoice_number),
        ];
    }
}
