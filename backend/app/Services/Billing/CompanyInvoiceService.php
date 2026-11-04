<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\InvoiceStudent;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\Billing\InvoiceStatus;
use App\Support\Billing\PaymentMethod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Billing > Invoices > Invoice by class: one invoice to the company that pays
 * for a company class (Billing > Companies), at one total typed in by staff
 * for a period — never per student. The class's students at that moment are
 * copied onto the invoice (InvoiceStudent) so it keeps showing who it
 * covered. A class is invoiced once: a second invoice is refused unless the
 * first was cancelled or voided.
 *
 * The figures still go through InvoiceService / PaymentService, so totals,
 * status, payments and the accounting income work exactly as for any other
 * invoice.
 */
final class CompanyInvoiceService
{
    /** The enrollments an invoice counts: everyone still in the class. */
    public const BILLED_STATUSES = [Enrollment::STATUS_NOT_STARTED, Enrollment::STATUS_ACTIVE, Enrollment::STATUS_EXAM_READY];

    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly PaymentService $payments,
    ) {}

    /**
     * @param  array{class_id:int, invoice_date?:string|null, due_date?:string|null, period_from:string, period_to:string, currency:string, amount:float|string, discount?:float|string|null, discount_reason?:string|null, notes?:string|null, received_amount?:float|string|null, payment_method?:string|null}  $data
     */
    public function create(array $data, User $actor): Invoice
    {
        return DB::connection('tenant')->transaction(function () use ($data, $actor) {
            /** @var SchoolClass $class */
            $class = SchoolClass::query()->with('company')->whereKey($data['class_id'])->lockForUpdate()->firstOrFail();

            if ($class->company === null) {
                throw ValidationException::withMessages(['class_id' => __('This class is not paid by a company. Set its company on the class first.')]);
            }

            $existing = $this->openInvoiceFor($class);
            if ($existing !== null) {
                throw ValidationException::withMessages(['class_id' => __('This class is already invoiced (:number). Cancel or void that invoice first to make a new one.', ['number' => $existing->invoice_number])]);
            }

            $enrollments = Enrollment::query()
                ->with(['student', 'coursePackage'])
                ->where('class_id', $class->getKey())
                ->whereIn('status', self::BILLED_STATUSES)
                ->orderBy('enrolled_at')->orderBy('id')
                ->get()
                ->unique('student_id')
                ->values();

            if ($enrollments->isEmpty()) {
                throw ValidationException::withMessages(['class_id' => __('This class has no students yet. Add students to the class first.')]);
            }

            // The invoice line's product decides which income account the
            // payment is booked to — the course most of the class is on.
            $package = $enrollments->pluck('coursePackage')->filter(fn ($p) => $p?->product_id !== null)
                ->groupBy('id')->sortByDesc(fn ($group) => $group->count())->first()?->first();

            if ($package === null) {
                throw ValidationException::withMessages(['class_id' => __("The course these students are on has no product linked, so the invoice can't be booked to an income account. Link a product to the course first.")]);
            }

            $count = $enrollments->count();
            $from = Carbon::parse($data['period_from'])->format('d-m-Y');
            $to = Carbon::parse($data['period_to'])->format('d-m-Y');

            $invoice = $this->invoices->create([
                'company_id' => $class->company_id,
                'class_id' => $class->getKey(),
                'invoice_date' => $data['invoice_date'] ?? now()->toDateString(),
                'due_date' => $data['due_date'] ?? null,
                'period_from' => $data['period_from'],
                'period_to' => $data['period_to'],
                'currency' => $data['currency'],
                'discount' => (float) ($data['discount'] ?? 0),
                'discount_reason' => $data['discount_reason'] ?? null,
                'notes' => $data['notes'] ?? null,
                'items' => [[
                    'product_id' => $package->product_id,
                    'unit_price' => (float) $data['amount'],
                    'description' => "{$package->name} — {$class->name} ({$count} students), {$from} to {$to}",
                    'reference_type' => SchoolClass::class,
                    'reference_id' => $class->getKey(),
                ]],
            ], $actor);

            foreach ($enrollments as $i => $enrollment) {
                InvoiceStudent::query()->create([
                    'invoice_id' => $invoice->getKey(),
                    'student_id' => $enrollment->student_id,
                    'student_code' => $enrollment->student?->student_code,
                    'student_name' => $enrollment->student?->fullName() ?? '—',
                    'position' => $i + 1,
                ]);
            }

            $received = (float) ($data['received_amount'] ?? 0);
            if ($received > 0) {
                $this->payments->record($invoice, [
                    'amount' => min($received, (float) $invoice->total),
                    'payment_method' => $data['payment_method'] ?? PaymentMethod::CASH,
                    'payment_date' => $data['invoice_date'] ?? now()->toDateString(),
                ], $actor);
            }

            return $invoice->refresh()->load(['company', 'schoolClass', 'invoiceStudents', 'items.product', 'payments']);
        });
    }

    /** The class's invoice that still counts (not cancelled or void), if any. */
    public function openInvoiceFor(SchoolClass $class): ?Invoice
    {
        return Invoice::query()
            ->where('class_id', $class->getKey())
            ->whereNotIn('status', [InvoiceStatus::CANCELLED, InvoiceStatus::VOID])
            ->first();
    }
}
