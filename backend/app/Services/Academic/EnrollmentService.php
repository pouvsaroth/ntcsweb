<?php

declare(strict_types=1);

namespace App\Services\Academic;

use App\Models\CoursePackage;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Billing\InvoiceService;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The package-based enrollment path: Student + Class + Course Package ->
 * Enrollment + Invoice + InvoiceItem, all in one transaction. Calls the
 * existing, unmodified InvoiceService::create() — the InvoiceItem's
 * reference_type/reference_id (already a first-class column on that model)
 * point back at the Enrollment, which is all AcademicReportService needs to
 * answer "why was this student charged $X?".
 *
 * The legacy book-based path (EnrollmentController::store()) is completely
 * untouched and keeps working exactly as before — both paths coexist on the
 * same `enrollments` table.
 *
 * Every price here is server-computed from the package's current price at
 * the moment of enrollment; nothing here accepts a fee/total from the
 * caller.
 */
final class EnrollmentService
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{student_id:int, class_id:int, course_package_id:int, enrolled_at?:string}  $data
     */
    public function enrollInPackage(array $data, User $actor): Enrollment
    {
        return DB::transaction(function () use ($data, $actor) {
            /** @var SchoolClass $class */
            $class = SchoolClass::query()->with('programOffering')->findOrFail($data['class_id']);
            /** @var CoursePackage $package */
            $package = CoursePackage::query()->with('product')->findOrFail($data['course_package_id']);

            $this->assertEnrollable($class, $package);

            $enrollment = Enrollment::query()->create([
                'student_id' => $data['student_id'],
                'class_id' => $class->getKey(),
                'course_package_id' => $package->getKey(),
                'academic_program_id' => $class->programOffering->academic_program_id,
                'study_mode_id' => $class->programOffering->study_mode_id,
                'enrolled_at' => $data['enrolled_at'] ?? now()->toDateString(),
                'fee' => $package->price,
                'status' => Enrollment::STATUS_ACTIVE,
            ]);

            $invoice = $this->invoices->create([
                'student_id' => $enrollment->student_id,
                'items' => [[
                    'product_id' => $package->product_id,
                    'unit_price' => (float) $enrollment->fee,
                    'description' => "Enrollment: {$package->name} ({$class->name})",
                    'reference_type' => Enrollment::class,
                    'reference_id' => $enrollment->getKey(),
                ]],
            ], $actor);

            $enrollment->load(['student', 'schoolClass', 'coursePackage', 'academicProgram', 'studyMode']);

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
     * to a class in the same program that also offers the same package; the
     * legacy book path has no program-offering concept to validate against
     * and is allowed to move freely, same as it always could.
     */
    public function transferClass(Enrollment $enrollment, SchoolClass $newClass, User $actor): Enrollment
    {
        return DB::transaction(function () use ($enrollment, $newClass, $actor) {
            /** @var Enrollment $enrollment */
            $enrollment = Enrollment::query()->whereKey($enrollment->getKey())->lockForUpdate()->firstOrFail();

            if ($enrollment->status !== Enrollment::STATUS_ACTIVE) {
                throw ValidationException::withMessages(['status' => 'Only an active enrollment can be transferred.']);
            }

            $newClass->loadMissing('programOffering');

            if ($enrollment->course_package_id !== null) {
                if ($newClass->programOffering === null || $newClass->programOffering->academic_program_id !== $enrollment->academic_program_id) {
                    throw ValidationException::withMessages(['class_id' => "The target class does not belong to this enrollment's program."]);
                }

                if (! $newClass->coursePackages()->whereKey($enrollment->course_package_id)->exists()) {
                    throw ValidationException::withMessages(['class_id' => 'The target class does not offer this package.']);
                }
            }

            $enrollment->auditTransferToClass = $newClass->name;
            $enrollment->update(['status' => Enrollment::STATUS_DROPPED]);

            $new = Enrollment::query()->create([
                'student_id' => $enrollment->student_id,
                'class_id' => $newClass->getKey(),
                'book_id' => $enrollment->book_id,
                'course_package_id' => $enrollment->course_package_id,
                'academic_program_id' => $enrollment->academic_program_id,
                'study_mode_id' => $newClass->programOffering->study_mode_id ?? $enrollment->study_mode_id,
                'enrolled_at' => now()->toDateString(),
                'fee' => $enrollment->fee,
                'status' => Enrollment::STATUS_ACTIVE,
            ]);

            return $new->load(['student', 'schoolClass', 'coursePackage', 'book']);
        });
    }

    private function assertEnrollable(SchoolClass $class, CoursePackage $package): void
    {
        if (! $package->is_active) {
            throw ValidationException::withMessages(['course_package_id' => 'This package is not active.']);
        }

        if ($class->programOffering === null) {
            throw ValidationException::withMessages(['class_id' => 'This class is not linked to a program offering and cannot be enrolled into via package registration.']);
        }

        if ((int) $package->academic_program_id !== (int) $class->programOffering->academic_program_id) {
            throw ValidationException::withMessages(['course_package_id' => "This package does not belong to the class's program."]);
        }

        if (! $class->coursePackages()->whereKey($package->getKey())->exists()) {
            throw ValidationException::withMessages(['course_package_id' => 'This package is not offered by the selected class.']);
        }
    }
}
