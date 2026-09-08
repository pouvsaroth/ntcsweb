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
 * billing concept. `enrollments`/`academic_programs` live in the tenant
 * database while `invoice_items`/`invoices`/`course_packages` are still
 * central, so these can no longer be single SQL joins — each method
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
     * `enrollments`/`invoice_items`/`invoices`/`academic_programs` all live
     * in the tenant database now, so that leg is one ordinary join; only the
     * hop out to `academic_programs` still needs its own query (it's not
     * part of the joined query above), merged in PHP.
     *
     * @return list<array{academic_program_id: int, program_name: string, revenue: float, enrollment_count: int}>
     */
    public function programRevenue(?string $dateFrom = null, ?string $dateTo = null): array
    {
        $query = Enrollment::query()
            ->join('invoice_items', function ($join) {
                $join->on('invoice_items.reference_id', '=', 'enrollments.id')
                    ->where('invoice_items.reference_type', '=', Enrollment::class);
            })
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->whereNotNull('enrollments.academic_program_id')
            ->whereNotIn('invoices.status', [InvoiceStatus::CANCELLED, InvoiceStatus::VOID]);

        if ($dateFrom !== null) {
            $query->whereDate('invoices.invoice_date', '>=', $dateFrom);
        }
        if ($dateTo !== null) {
            $query->whereDate('invoices.invoice_date', '<=', $dateTo);
        }

        $rows = $query
            ->select(
                'enrollments.academic_program_id',
                DB::raw('SUM(invoice_items.total) as revenue'),
                DB::raw('COUNT(DISTINCT enrollments.id) as enrollment_count'),
            )
            ->groupBy('enrollments.academic_program_id')
            ->get()
            ->keyBy('academic_program_id');

        if ($rows->isEmpty()) {
            return [];
        }

        return AcademicProgram::query()
            ->whereIn('id', $rows->keys())
            ->get(['id', 'name'])
            ->map(fn (AcademicProgram $program) => [
                'academic_program_id' => (int) $program->id,
                'program_name' => $program->name,
                'revenue' => (float) $rows[$program->id]->revenue,
                'enrollment_count' => (int) $rows[$program->id]->enrollment_count,
            ])
            ->sortByDesc('revenue')
            ->values()
            ->all();
    }

    /**
     * `enrollments`/`invoice_items`/`invoices` all live in the tenant
     * database now, so that leg is one ordinary join; only the hop out to
     * `course_packages` (still central) needs its own query, merged in PHP.
     *
     * @return list<array{course_package_id: int, package_name: string, students: int, revenue: float}>
     */
    public function packageSales(?string $dateFrom = null, ?string $dateTo = null): array
    {
        $query = Enrollment::query()
            ->join('invoice_items', function ($join) {
                $join->on('invoice_items.reference_id', '=', 'enrollments.id')
                    ->where('invoice_items.reference_type', '=', Enrollment::class);
            })
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->whereNotNull('enrollments.course_package_id')
            ->whereNotIn('invoices.status', [InvoiceStatus::CANCELLED, InvoiceStatus::VOID]);

        if ($dateFrom !== null) {
            $query->whereDate('invoices.invoice_date', '>=', $dateFrom);
        }
        if ($dateTo !== null) {
            $query->whereDate('invoices.invoice_date', '<=', $dateTo);
        }

        $rows = $query
            ->select(
                'enrollments.course_package_id',
                DB::raw('COUNT(DISTINCT enrollments.student_id) as students'),
                DB::raw('SUM(invoice_items.total) as revenue'),
            )
            ->groupBy('enrollments.course_package_id')
            ->get()
            ->keyBy('course_package_id');

        if ($rows->isEmpty()) {
            return [];
        }

        return CoursePackage::query()
            ->whereIn('id', $rows->keys())
            ->get(['id', 'name'])
            ->map(fn (CoursePackage $package) => [
                'course_package_id' => (int) $package->id,
                'package_name' => $package->name,
                'students' => (int) $rows[$package->id]->students,
                'revenue' => (float) $rows[$package->id]->revenue,
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
        $enrollmentCounts = Enrollment::query()
            ->where('status', '!=', Enrollment::STATUS_DROPPED)
            ->select('class_id', DB::raw('COUNT(*) as students'))
            ->groupBy('class_id')
            ->pluck('students', 'class_id');

        // `enrollments`/`invoice_items`/`invoices` all live in the tenant
        // database now, so this is one ordinary join grouped by class_id.
        $revenue = Enrollment::query()
            ->join('invoice_items', function ($join) {
                $join->on('invoice_items.reference_id', '=', 'enrollments.id')
                    ->where('invoice_items.reference_type', '=', Enrollment::class);
            })
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->whereNotIn('invoices.status', [InvoiceStatus::CANCELLED, InvoiceStatus::VOID])
            ->select('enrollments.class_id', DB::raw('SUM(invoice_items.total) as revenue'))
            ->groupBy('enrollments.class_id')
            ->pluck('revenue', 'class_id');

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
        $row = DB::connection('tenant')->table('invoices')
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
