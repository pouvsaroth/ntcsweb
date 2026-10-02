<?php

declare(strict_types=1);

namespace App\Services\Academic;

use App\Models\CoursePackage;
use App\Models\Enrollment;
use App\Models\EnrollmentStatusHistory;
use App\Models\EnrollmentTransferHistory;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\CurrencyConversionService;
use App\Services\Billing\InvoiceService;
use App\Services\Billing\PaymentService;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditLogger;
use App\Support\Billing\PaymentMethod;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The package-based enrollment path: Student + Class + Course Package ->
 * Enrollment + Invoice + InvoiceItem (+ optional Payment), all in one
 * transaction. Calls the existing, unmodified InvoiceService::create()/
 * PaymentService::record() — the InvoiceItem's reference_type/reference_id
 * (already a first-class column on that model) point back at the
 * Enrollment, which is all AcademicReportService needs to answer "why was
 * this student charged $X?".
 *
 * The fee is server-computed from the package's `fee_type` tier
 * (monthly/term/video/monthly_online/term_online) at the moment of
 * enrollment; nothing here accepts a fee/total from the caller. A discount
 * amount/reason IS caller-supplied (there's no catalog value to derive it
 * from), capped server-side at the fee. `received_amount` — how much cash
 * was actually handed over, which can legitimately exceed what's owed — is
 * also caller-supplied, but the `Payment` actually recorded is capped at
 * the invoice total; any excess is "change" the cashier hands back and is
 * never persisted as paid.
 *
 * The invoice is always billed in the school's own `default_currency`, not
 * the package's — a package priced in USD still produces a Riel invoice for
 * a Riel school, converted at the rate in effect on the enrollment date (see
 * CurrencyConversionService). `discount_price`/`received_amount` are taken
 * as already being in that same target currency (the enrollment form shows
 * and collects them that way) — only the package's own fee needs converting.
 */
final class EnrollmentService
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly PaymentService $payments,
        private readonly CurrencyConversionService $currencyConversion,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{student_id:int, class_id:int, course_package_id:int, fee_type:string, table_id?:int|null, enrolled_at?:string, discount_price?:float|null, discount_reason?:string|null, received_amount?:float|null, payment_method?:string|null}  $data
     */
    public function enrollInPackage(array $data, User $actor): Enrollment
    {
        return DB::connection('tenant')->transaction(function () use ($data, $actor) {
            /** @var SchoolClass $class */
            $class = SchoolClass::query()->with('academicProgram')->findOrFail($data['class_id']);
            /** @var CoursePackage $package */
            $package = CoursePackage::query()->with('product')->findOrFail($data['course_package_id']);

            $this->assertEnrollable($class, $package);

            $feeType = $data['fee_type'];
            $feeColumn = 'fee_'.$feeType;
            $fee = (float) $package->{$feeColumn};
            $enrolledAt = $data['enrolled_at'] ?? now()->toDateString();

            // Course packages are priced in whatever currency they were set
            // up in (almost always USD), but the invoice this enrollment
            // produces is always billed in the SCHOOL's own currency — a
            // Riel school's staff types the discount/received amount in
            // Riel (see EnrollmentPackageForm.vue), so the fee they're
            // comparing against has to already be in Riel too.
            $invoiceCurrency = $this->context->getOrFail()->default_currency;

            if ($package->currency !== $invoiceCurrency) {
                $rate = $this->currencyConversion->rateForDate($enrolledAt);

                if ($rate === null) {
                    throw ValidationException::withMessages([
                        'course_package_id' => "This school bills in {$invoiceCurrency}, but no exchange rate has been entered yet to convert this course's {$package->currency} price. Add one under Currency Rates first.",
                    ]);
                }

                $fee = $this->currencyConversion->convert($fee, $package->currency, $invoiceCurrency, $rate);
                $fee = $invoiceCurrency === Tenant::CURRENCY_KHR ? round($fee) : round($fee, 2);
            }

            $enrollment = Enrollment::query()->create([
                'student_id' => $data['student_id'],
                'class_id' => $class->getKey(),
                'table_id' => $data['table_id'] ?? null,
                'course_package_id' => $package->getKey(),
                'academic_program_id' => $class->academic_program_id,
                'enrolled_at' => $enrolledAt,
                'enrollments_code' => $this->generateEnrollmentCode($data['student_id']),
                'status' => Enrollment::STATUS_ACTIVE,
            ]);

            $invoice = $this->invoices->create([
                'student_id' => $enrollment->student_id,
                'currency' => $invoiceCurrency,
                'discount' => (float) ($data['discount_price'] ?? 0),
                'discount_reason' => $data['discount_reason'] ?? null,
                'payment_type' => $feeType,
                'items' => [[
                    'product_id' => $package->product_id,
                    'unit_price' => $fee,
                    'description' => "Enrollment: {$package->name} ({$class->name})",
                    'reference_type' => Enrollment::class,
                    'reference_id' => $enrollment->getKey(),
                ]],
            ], $actor);

            $receivedAmount = (float) ($data['received_amount'] ?? 0);
            if ($receivedAmount > 0) {
                $this->payments->record($invoice, [
                    'amount' => min($receivedAmount, (float) $invoice->total),
                    'payment_method' => $data['payment_method'] ?? PaymentMethod::CASH,
                    'payment_date' => $enrolledAt,
                ], $actor);
                $invoice->refresh();
            }

            $enrollment->load(['student', 'schoolClass', 'table', 'coursePackage', 'academicProgram']);

            // Transient — never persisted, just carried through to
            // EnrollmentResource so the admin UI can offer "Save and Print"
            // (jump straight to this invoice) without a second round trip.
            // invoice_number rides along too so the frontend can go straight
            // to downloading the PDF instead of fetching the invoice first
            // just to learn its own number.
            $enrollment->setAttribute('invoice_id', $invoice->getKey());
            $enrollment->setAttribute('invoice_number', $invoice->invoice_number);

            $this->audit->log(
                AuditAction::ENROLLMENT_INVOICED,
                'Enrollments',
                $enrollment,
                new: ['invoice_id' => $invoice->getKey(), 'invoice_number' => $invoice->invoice_number, 'fee' => $fee, 'currency' => $invoiceCurrency],
                description: "Enrolled {$enrollment->student->auditDisplayName()} in {$class->name} — {$package->name} ({$fee} {$invoiceCurrency}), invoice {$invoice->invoice_number}",
                actor: $actor,
            );

            return $enrollment;
        });
    }

    /**
     * The "manage status and history" menu — every transition writes an
     * EnrollmentStatusHistory row, and the reason/date (when required — see
     * Enrollment::STATUSES_REQUIRING_REASON) is also denormalized onto the
     * enrollment itself for quick display. Distinct from cancel() below,
     * which collapses to STATUS_DROPPED — that stays internal bookkeeping,
     * never a choice made through here. (Rows transferred before transfers
     * became in-place updates are also STATUS_DROPPED.)
     */
    public function changeStatus(Enrollment $enrollment, string $status, ?string $reason, ?string $effectiveDate, User $actor): Enrollment
    {
        return DB::connection('tenant')->transaction(function () use ($enrollment, $status, $reason, $effectiveDate, $actor) {
            /** @var Enrollment $enrollment */
            $enrollment = Enrollment::query()->whereKey($enrollment->getKey())->lockForUpdate()->firstOrFail();

            if ($enrollment->status === Enrollment::STATUS_DROPPED) {
                throw ValidationException::withMessages(['status' => 'This enrollment was closed by a cancellation or transfer and can no longer be managed here.']);
            }

            EnrollmentStatusHistory::query()->create([
                'enrollment_id' => $enrollment->getKey(),
                'from_status' => $enrollment->status,
                'to_status' => $status,
                'reason' => $reason,
                'effective_date' => $effectiveDate,
                'changed_by' => $actor->getKey(),
            ]);

            $changes = ['status' => $status];

            // Returning to Studying: their table may have gone to someone
            // else while they weren't holding it (see
            // Enrollment::TABLE_HOLDING_STATUS) — clear it rather than seat
            // two students at one table; staff pick a new one afterwards.
            if ($status === Enrollment::TABLE_HOLDING_STATUS && $enrollment->table_id !== null) {
                $tableTaken = Enrollment::query()
                    ->where('class_id', $enrollment->class_id)
                    ->where('table_id', $enrollment->table_id)
                    ->where('status', Enrollment::TABLE_HOLDING_STATUS)
                    ->whereKeyNot($enrollment->getKey())
                    ->exists();

                if ($tableTaken) {
                    $changes['table_id'] = null;
                }
            }

            $enrollment->auditReason = $reason;
            $enrollment->update($changes);

            return $enrollment;
        });
    }

    public function cancel(Enrollment $enrollment, string $reason, User $actor): Enrollment
    {
        return DB::connection('tenant')->transaction(function () use ($enrollment, $reason) {
            /** @var Enrollment $enrollment */
            $enrollment = Enrollment::query()->whereKey($enrollment->getKey())->lockForUpdate()->firstOrFail();

            if ($enrollment->status === Enrollment::STATUS_DROPPED) {
                throw ValidationException::withMessages(['status' => 'This enrollment is already dropped.']);
            }

            $enrollment->auditReason = $reason;
            $enrollment->update(['status' => Enrollment::STATUS_DROPPED]);

            return $enrollment;
        });
    }

    /**
     * Moves an active enrollment to a different class/table, updating the
     * same row in place (see recordTransfer()) — no re-billing, no new
     * enrollment code; the before/after goes to EnrollmentTransferHistory.
     * A package-based
     * enrollment may only transfer to a class in the same program (a class
     * is just a schedule/room/teacher — it doesn't need to "offer" the
     * package); the legacy book path has no program concept to validate
     * against and is allowed to move freely, same as it always could.
     *
     * Passing $newPackage (different from the enrollment's current one)
     * additionally changes the COURSE, not just the room/schedule, and
     * recomputes the fee from its $feeType tier — but only while nothing has
     * been paid yet (Enrollment::isPaid()). A paid enrollment may still move
     * to a different class/room of the *same* course; changing the course
     * itself at that point would need a refund/re-invoice, not a transfer.
     */
    public function transferClass(
        Enrollment $enrollment,
        SchoolClass $newClass,
        User $actor,
        ?int $tableId = null,
        ?CoursePackage $newPackage = null,
        ?string $feeType = null,
    ): Enrollment {
        return DB::connection('tenant')->transaction(function () use ($enrollment, $newClass, $actor, $tableId, $newPackage, $feeType) {
            /** @var Enrollment $enrollment */
            $enrollment = Enrollment::query()->whereKey($enrollment->getKey())->lockForUpdate()->firstOrFail();

            if ($enrollment->status !== Enrollment::STATUS_ACTIVE) {
                throw ValidationException::withMessages(['status' => 'Only an active enrollment can be transferred.']);
            }

            $changingCourse = $newPackage !== null && (int) $newPackage->getKey() !== (int) $enrollment->course_package_id;

            if ($changingCourse) {
                if ($enrollment->isPaid()) {
                    throw ValidationException::withMessages(['course_package_id' => 'This enrollment has already been paid — the course cannot be changed, but the class can.']);
                }

                if ($newClass->academic_program_id === null || (int) $newClass->academic_program_id !== (int) $newPackage->academic_program_id) {
                    throw ValidationException::withMessages(['class_id' => "The target class does not belong to the selected course's program."]);
                }

                $resolvedFeeType = $feeType ?? 'monthly';
                $feeColumn = 'fee_'.$resolvedFeeType;

                if ($newPackage->{$feeColumn} === null) {
                    throw ValidationException::withMessages(['fee_type' => 'This course does not offer the selected fee type.']);
                }

                $packageId = $newPackage->getKey();
                $programId = $newPackage->academic_program_id;
            } else {
                if ($enrollment->course_package_id !== null) {
                    if ($newClass->academic_program_id === null || (int) $newClass->academic_program_id !== (int) $enrollment->academic_program_id) {
                        throw ValidationException::withMessages(['class_id' => "The target class does not belong to this enrollment's program."]);
                    }
                }

                $packageId = $enrollment->course_package_id;
                $programId = $enrollment->academic_program_id;
            }

            $enrollment->auditTransferToClass = $newClass->name;

            $this->recordTransfer($enrollment, [
                'class_id' => $newClass->getKey(),
                'table_id' => $tableId,
                'course_package_id' => $packageId,
                'academic_program_id' => $programId,
            ], $actor);

            return $enrollment->load(['student', 'schoolClass', 'table', 'coursePackage']);
        });
    }

    /**
     * Reseats a student within their current class — see
     * EnrollmentController::changeTable(). Same in-place update + history
     * row as transferClass(), just without the class/course checks.
     */
    public function changeTable(Enrollment $enrollment, ?int $tableId, User $actor): Enrollment
    {
        return DB::connection('tenant')->transaction(function () use ($enrollment, $tableId, $actor) {
            /** @var Enrollment $enrollment */
            $enrollment = Enrollment::query()->whereKey($enrollment->getKey())->lockForUpdate()->firstOrFail();

            $this->recordTransfer($enrollment, ['table_id' => $tableId], $actor);

            return $enrollment;
        });
    }

    /**
     * Applies a class/table/course change to the enrollment itself and logs
     * the before/after in EnrollmentTransferHistory. The enrollment keeps its
     * id, code, enrolled_at, invoices and attendance — only these columns
     * move. A no-op (nothing actually different) writes no history row.
     *
     * @param  array{class_id?:int, table_id?:int|null, course_package_id?:int|null, academic_program_id?:int|null}  $changes
     */
    private function recordTransfer(Enrollment $enrollment, array $changes, User $actor): void
    {
        $enrollment->fill($changes);

        if (! $enrollment->isDirty(['class_id', 'table_id', 'course_package_id'])) {
            return;
        }

        EnrollmentTransferHistory::query()->create([
            'enrollment_id' => $enrollment->getKey(),
            'from_class_id' => $enrollment->getOriginal('class_id'),
            'to_class_id' => $enrollment->class_id,
            'from_table_id' => $enrollment->getOriginal('table_id'),
            'to_table_id' => $enrollment->table_id,
            'from_course_package_id' => $enrollment->getOriginal('course_package_id'),
            'to_course_package_id' => $enrollment->course_package_id,
            'changed_by' => $actor->getKey(),
        ]);

        $enrollment->save();
    }

    /**
     * `{student_code}-{NN}`, e.g. a student `NTS-000008`'s first enrollment
     * is `NTS-000008-01`, second is `NTS-000008-02` — every new Enrollment
     * row advances the sequence (a transfer keeps its code — see
     * transferClass()).
     * Locking the student row (not just relying on the caller's own
     * transaction) is what makes two concurrent enrollments for the same
     * student serialize instead of racing to the same sequence number.
     *
     * The next number is the highest suffix already used, not a row count —
     * a student whose enrollment history has a gap (any row created outside
     * this method, or removed) would otherwise have `count()+1` collide with
     * a code that already exists, which fails the whole enrollment with a
     * raw unique-constraint 500 instead of just skipping the taken number.
     */
    private function generateEnrollmentCode(int $studentId): string
    {
        $student = Student::query()->whereKey($studentId)->lockForUpdate()->firstOrFail();

        $lastSequence = Enrollment::query()
            ->where('student_id', $studentId)
            ->pluck('enrollments_code')
            ->map(fn (string $code) => (int) Str::afterLast($code, '-'))
            ->max() ?? 0;

        return sprintf('%s-%02d', $student->student_code, $lastSequence + 1);
    }

    private function assertEnrollable(SchoolClass $class, CoursePackage $package): void
    {
        if (! $package->is_active) {
            throw ValidationException::withMessages(['course_package_id' => 'This package is not active.']);
        }

        if ($class->academic_program_id === null) {
            throw ValidationException::withMessages(['class_id' => 'This class is not linked to a program and cannot be enrolled into via package registration.']);
        }

        if ((int) $package->academic_program_id !== (int) $class->academic_program_id) {
            throw ValidationException::withMessages(['course_package_id' => "This package does not belong to the class's program."]);
        }
    }
}
