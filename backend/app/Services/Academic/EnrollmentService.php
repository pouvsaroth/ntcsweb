<?php

declare(strict_types=1);

namespace App\Services\Academic;

use App\Models\CoursePackage;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Billing\InvoiceService;
use App\Services\Billing\PaymentService;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditLogger;
use App\Support\Billing\PaymentMethod;
use Illuminate\Support\Facades\DB;
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
 * The legacy book-based path (EnrollmentController::store()) is completely
 * untouched and keeps working exactly as before — both paths coexist on the
 * same `enrollments` table.
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
 */
final class EnrollmentService
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly PaymentService $payments,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{student_id:int, class_id:int, course_package_id:int, fee_type:string, table_id?:int|null, enrolled_at?:string, discount_price?:float|null, discount_reason?:string|null, received_amount?:float|null, payment_method?:string|null}  $data
     */
    public function enrollInPackage(array $data, User $actor): Enrollment
    {
        return DB::transaction(function () use ($data, $actor) {
            /** @var SchoolClass $class */
            $class = SchoolClass::query()->with('academicProgram')->findOrFail($data['class_id']);
            /** @var CoursePackage $package */
            $package = CoursePackage::query()->with('product')->findOrFail($data['course_package_id']);

            $this->assertEnrollable($class, $package);

            $feeType = $data['fee_type'];
            $feeColumn = 'fee_'.$feeType;
            $fee = (float) $package->{$feeColumn};

            $enrollment = Enrollment::query()->create([
                'student_id' => $data['student_id'],
                'class_id' => $class->getKey(),
                'table_id' => $data['table_id'] ?? null,
                'course_package_id' => $package->getKey(),
                'academic_program_id' => $class->academic_program_id,
                'enrolled_at' => $data['enrolled_at'] ?? now()->toDateString(),
                'fee' => $fee,
                'fee_type' => $feeType,
                'status' => Enrollment::STATUS_ACTIVE,
            ]);

            $invoice = $this->invoices->create([
                'student_id' => $enrollment->student_id,
                'discount' => (float) ($data['discount_price'] ?? 0),
                'discount_reason' => $data['discount_reason'] ?? null,
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
                    'payment_date' => $data['enrolled_at'] ?? now()->toDateString(),
                ], $actor);
                $invoice->refresh();
            }

            $enrollment->load(['student', 'schoolClass', 'table', 'coursePackage', 'academicProgram', 'studyMode']);

            $this->audit->log(
                AuditAction::ENROLLMENT_INVOICED,
                'Enrollments',
                $enrollment,
                new: ['invoice_id' => $invoice->getKey(), 'invoice_number' => $invoice->invoice_number, 'fee' => (float) $enrollment->fee],
                description: "Enrolled {$enrollment->student->auditDisplayName()} in {$class->name} — {$package->name} (\${$enrollment->fee}), invoice {$invoice->invoice_number}",
                actor: $actor,
            );

            return $enrollment;
        });
    }

    public function cancel(Enrollment $enrollment, string $reason, User $actor): Enrollment
    {
        return DB::transaction(function () use ($enrollment, $reason, $actor) {
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
     * Moves an active enrollment to a different class, closing the old row
     * (status=dropped) and opening a fresh one in the target class carrying
     * the same book/package/fee forward — no re-billing, full history
     * preserved on both rows. A package-based enrollment may only transfer
     * to a class in the same program (a class is just a schedule/room/
     * teacher — it doesn't need to "offer" the package); the legacy book
     * path has no program concept to validate against and is allowed to
     * move freely, same as it always could.
     */
    public function transferClass(Enrollment $enrollment, SchoolClass $newClass, User $actor, ?int $tableId = null): Enrollment
    {
        return DB::transaction(function () use ($enrollment, $newClass, $actor, $tableId) {
            /** @var Enrollment $enrollment */
            $enrollment = Enrollment::query()->whereKey($enrollment->getKey())->lockForUpdate()->firstOrFail();

            if ($enrollment->status !== Enrollment::STATUS_ACTIVE) {
                throw ValidationException::withMessages(['status' => 'Only an active enrollment can be transferred.']);
            }

            if ($enrollment->course_package_id !== null) {
                if ($newClass->academic_program_id === null || $newClass->academic_program_id !== $enrollment->academic_program_id) {
                    throw ValidationException::withMessages(['class_id' => "The target class does not belong to this enrollment's program."]);
                }
            }

            $enrollment->auditTransferToClass = $newClass->name;
            $enrollment->update(['status' => Enrollment::STATUS_DROPPED]);

            $new = Enrollment::query()->create([
                'student_id' => $enrollment->student_id,
                'class_id' => $newClass->getKey(),
                'table_id' => $tableId,
                'book_id' => $enrollment->book_id,
                'course_package_id' => $enrollment->course_package_id,
                'academic_program_id' => $enrollment->academic_program_id,
                'study_mode_id' => $enrollment->study_mode_id,
                'enrolled_at' => now()->toDateString(),
                'fee' => $enrollment->fee,
                'fee_type' => $enrollment->fee_type,
                'status' => Enrollment::STATUS_ACTIVE,
            ]);

            return $new->load(['student', 'schoolClass', 'table', 'coursePackage', 'book']);
        });
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
