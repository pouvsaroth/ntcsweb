<?php

declare(strict_types=1);

namespace App\Services\Academic;

use App\Models\AcademicProgram;
use App\Models\CoursePackage;
use App\Models\Enrollment;
use App\Models\Student;
use App\Support\Billing\InvoiceStatus;
use Illuminate\Support\Facades\DB;

/**
 * Every figure here is a SQL SUM/COUNT/GROUP BY, never a PHP loop over
 * loaded models — mirrors AccountingReportService/AssetReportService.
 * Program revenue and package sales both trace through
 * `invoice_items.reference_type/reference_id` (already a first-class,
 * existing column pair — see InvoiceItem's own docblock) back to the
 * Enrollment that produced the charge, rather than introducing any new
 * billing concept. `enrollments` lives in the tenant database while
 * `invoice_items`/`invoices`/`academic_programs`/`course_packages` are
 * still central, so these can no longer be single SQL joins — each method
 * resolves the enrollment-side grouping key first, then aggregates the
 * central-side amounts by that key, and merges the two in PHP.
 */
final class AcademicReportService
{
    /**
     * @return array<string, int>
     */
    public function enrollmentCountsByStatus(): array
    {
        return Enrollment::query()
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * @return list<array{academic_program_id: int, program_name: string, total: int}>
     */
    public function enrollmentCountsByProgram(): array
    {
        $counts = Enrollment::query()
            ->whereNotNull('academic_program_id')
            ->select('academic_program_id', DB::raw('COUNT(*) as total'))
            ->groupBy('academic_program_id')
            ->pluck('total', 'academic_program_id');

        if ($counts->isEmpty()) {
            return [];
        }

        return AcademicProgram::query()
            ->whereIn('id', $counts->keys())
            ->get(['id', 'name'])
            ->map(fn (AcademicProgram $program) => [
                'academic_program_id' => (int) $program->id,
                'program_name' => $program->name,
                'total' => (int) $counts[$program->id],
            ])
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    /**
     * Revenue attributed to each academic program, via the InvoiceItem's
     * own reference back to the Enrollment that produced the charge.
     *
     * @return list<array{academic_program_id: int, program_name: string, revenue: float, enrollment_count: int}>
     */
    public function programRevenue(?string $dateFrom = null, ?string $dateTo = null): array
    {
        $enrollmentPrograms = Enrollment::query()->whereNotNull('academic_program_id')->pluck('academic_program_id', 'id');

        if ($enrollmentPrograms->isEmpty()) {
            return [];
        }

        $query = DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoice_items.reference_type', '=', Enrollment::class)
            ->whereIn('invoice_items.reference_id', $enrollmentPrograms->keys())
            ->whereNotIn('invoices.status', [InvoiceStatus::CANCELLED, InvoiceStatus::VOID]);

        if ($dateFrom !== null) {
            $query->whereDate('invoices.invoice_date', '>=', $dateFrom);
        }
        if ($dateTo !== null) {
            $query->whereDate('invoices.invoice_date', '<=', $dateTo);
        }

        $rows = $query
            ->select('invoice_items.reference_id as enrollment_id', DB::raw('SUM(invoice_items.total) as revenue'))
            ->groupBy('invoice_items.reference_id')
            ->get();

        $byProgram = [];
        foreach ($rows as $row) {
            $programId = $enrollmentPrograms[$row->enrollment_id] ?? null;
            if ($programId === null) {
                continue;
            }

            $byProgram[$programId]['revenue'] = ($byProgram[$programId]['revenue'] ?? 0) + (float) $row->revenue;
            $byProgram[$programId]['enrollment_ids'][$row->enrollment_id] = true;
        }

        if ($byProgram === []) {
            return [];
        }

        return AcademicProgram::query()
            ->whereIn('id', array_keys($byProgram))
            ->get(['id', 'name'])
            ->map(fn (AcademicProgram $program) => [
                'academic_program_id' => (int) $program->id,
                'program_name' => $program->name,
                'revenue' => (float) $byProgram[$program->id]['revenue'],
                'enrollment_count' => count($byProgram[$program->id]['enrollment_ids']),
            ])
            ->sortByDesc('revenue')
            ->values()
            ->all();
    }

    /**
     * @return list<array{course_package_id: int, package_name: string, students: int, revenue: float}>
     */
    public function packageSales(?string $dateFrom = null, ?string $dateTo = null): array
    {
        $enrollments = Enrollment::query()->whereNotNull('course_package_id')->get(['id', 'course_package_id', 'student_id'])->keyBy('id');

        if ($enrollments->isEmpty()) {
            return [];
        }

        $query = DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoice_items.reference_type', '=', Enrollment::class)
            ->whereIn('invoice_items.reference_id', $enrollments->keys())
            ->whereNotIn('invoices.status', [InvoiceStatus::CANCELLED, InvoiceStatus::VOID]);

        if ($dateFrom !== null) {
            $query->whereDate('invoices.invoice_date', '>=', $dateFrom);
        }
        if ($dateTo !== null) {
            $query->whereDate('invoices.invoice_date', '<=', $dateTo);
        }

        $rows = $query
            ->select('invoice_items.reference_id as enrollment_id', DB::raw('SUM(invoice_items.total) as revenue'))
            ->groupBy('invoice_items.reference_id')
            ->get();

        $byPackage = [];
        foreach ($rows as $row) {
            $enrollment = $enrollments[$row->enrollment_id] ?? null;
            if ($enrollment === null) {
                continue;
            }

            $packageId = $enrollment->course_package_id;
            $byPackage[$packageId]['revenue'] = ($byPackage[$packageId]['revenue'] ?? 0) + (float) $row->revenue;
            $byPackage[$packageId]['student_ids'][$enrollment->student_id] = true;
        }

        if ($byPackage === []) {
            return [];
        }

        return CoursePackage::query()
            ->whereIn('id', array_keys($byPackage))
            ->get(['id', 'name'])
            ->map(fn (CoursePackage $package) => [
                'course_package_id' => (int) $package->id,
                'package_name' => $package->name,
                'students' => count($byPackage[$package->id]['student_ids']),
                'revenue' => (float) $byPackage[$package->id]['revenue'],
            ])
            ->sortByDesc('revenue')
            ->values()
            ->all();
    }

    /**
     * @return list<array{class_id: int, class_name: string, teacher: ?string, capacity: ?int, students: int, revenue: float}>
     */
    public function classReport(): array
    {
        $enrollmentCounts = DB::connection('tenant')->table('enrollments')
            ->where('status', '!=', Enrollment::STATUS_DROPPED)
            ->select('class_id', DB::raw('COUNT(*) as students'))
            ->groupBy('class_id')
            ->pluck('students', 'class_id');

        // `enrollments` lives in the tenant database while `invoice_items`/
        // `invoices` are still central, so the enrollment -> class mapping
        // is resolved first and the revenue sums are merged onto it in PHP
        // rather than a single join — same technique as programRevenue()/
        // packageSales() above.
        $enrollmentClasses = Enrollment::query()->pluck('class_id', 'id');

        $revenue = [];

        if ($enrollmentClasses->isNotEmpty()) {
            $revenueRows = DB::table('invoice_items')
                ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
                ->where('invoice_items.reference_type', '=', Enrollment::class)
                ->whereIn('invoice_items.reference_id', $enrollmentClasses->keys())
                ->whereNotIn('invoices.status', [InvoiceStatus::CANCELLED, InvoiceStatus::VOID])
                ->select('invoice_items.reference_id as enrollment_id', DB::raw('SUM(invoice_items.total) as revenue'))
                ->groupBy('invoice_items.reference_id')
                ->get();

            foreach ($revenueRows as $row) {
                $classId = $enrollmentClasses[$row->enrollment_id] ?? null;
                if ($classId === null) {
                    continue;
                }

                $revenue[$classId] = ($revenue[$classId] ?? 0) + (float) $row->revenue;
            }
        }

        $classes = DB::table('classes')
            ->select('classes.id as class_id', 'classes.name as class_name', 'classes.teacher_id', 'classes.capacity as capacity')
            ->whereNull('classes.deleted_at')
            ->orderBy('classes.name')
            ->get();

        // `staff` lives in the tenant database, `classes` in the central
        // one, so the teacher's name is resolved as a second query rather
        // than a join — a cross-database join isn't possible in Postgres.
        $teacherIds = $classes->pluck('teacher_id')->filter()->unique()->values();

        $teacherNames = DB::connection('tenant')->table('staff')
            ->whereIn('id', $teacherIds)
            ->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(fn ($row) => [$row->id => trim("{$row->first_name} {$row->last_name}")]);

        return $classes
            ->map(fn ($row) => [
                'class_id' => (int) $row->class_id,
                'class_name' => $row->class_name,
                'teacher' => $row->teacher_id !== null ? ($teacherNames[$row->teacher_id] ?? null) : null,
                'capacity' => $row->capacity !== null ? (int) $row->capacity : null,
                'students' => (int) ($enrollmentCounts[$row->class_id] ?? 0),
                'revenue' => (float) ($revenue[$row->class_id] ?? 0),
            ])
            ->all();
    }

    /**
     * @return array{total_invoiced: float, total_paid: float, balance: float}
     */
    public function studentFinancialSummary(Student $student): array
    {
        $row = DB::table('invoices')
            ->where('student_id', $student->getKey())
            ->whereNotIn('status', [InvoiceStatus::CANCELLED, InvoiceStatus::VOID])
            ->selectRaw('COALESCE(SUM(total), 0) as total_invoiced, COALESCE(SUM(paid_amount), 0) as total_paid, COALESCE(SUM(balance), 0) as balance')
            ->first();

        return [
            'total_invoiced' => (float) $row->total_invoiced,
            'total_paid' => (float) $row->total_paid,
            'balance' => (float) $row->balance,
        ];
    }
}
