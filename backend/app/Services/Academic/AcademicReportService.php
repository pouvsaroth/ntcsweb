<?php

declare(strict_types=1);

namespace App\Services\Academic;

use App\Models\Enrollment;
use App\Models\Student;
use App\Support\Billing\InvoiceStatus;
use Illuminate\Support\Facades\DB;

/**
 * Every figure here is a SQL SUM/COUNT/GROUP BY, never a PHP loop over
 * loaded models — mirrors AccountingReportService/AssetReportService.
 * Program revenue and package sales both join through
 * `invoice_items.reference_type/reference_id` (already a first-class,
 * existing column pair — see InvoiceItem's own docblock) back to the
 * Enrollment that produced the charge, rather than introducing any new
 * billing concept.
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
        return Enrollment::query()
            ->join('academic_programs', 'academic_programs.id', '=', 'enrollments.academic_program_id')
            ->select('academic_programs.id as academic_program_id', 'academic_programs.name as program_name', DB::raw('COUNT(enrollments.id) as total'))
            ->groupBy('academic_programs.id', 'academic_programs.name')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => ['academic_program_id' => (int) $row->academic_program_id, 'program_name' => $row->program_name, 'total' => (int) $row->total])
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
        $query = DB::table('invoice_items')
            ->join('enrollments', function ($join) {
                $join->on('enrollments.id', '=', 'invoice_items.reference_id')
                    ->where('invoice_items.reference_type', '=', Enrollment::class);
            })
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->join('academic_programs', 'academic_programs.id', '=', 'enrollments.academic_program_id')
            ->whereNotIn('invoices.status', [InvoiceStatus::CANCELLED, InvoiceStatus::VOID]);

        if ($dateFrom !== null) {
            $query->whereDate('invoices.invoice_date', '>=', $dateFrom);
        }
        if ($dateTo !== null) {
            $query->whereDate('invoices.invoice_date', '<=', $dateTo);
        }

        return $query
            ->select(
                'academic_programs.id as academic_program_id',
                'academic_programs.name as program_name',
                DB::raw('SUM(invoice_items.total) as revenue'),
                DB::raw('COUNT(DISTINCT enrollments.id) as enrollment_count'),
            )
            ->groupBy('academic_programs.id', 'academic_programs.name')
            ->orderByDesc('revenue')
            ->get()
            ->map(fn ($row) => [
                'academic_program_id' => (int) $row->academic_program_id,
                'program_name' => $row->program_name,
                'revenue' => (float) $row->revenue,
                'enrollment_count' => (int) $row->enrollment_count,
            ])
            ->all();
    }

    /**
     * @return list<array{course_package_id: int, package_name: string, students: int, revenue: float}>
     */
    public function packageSales(?string $dateFrom = null, ?string $dateTo = null): array
    {
        $query = DB::table('invoice_items')
            ->join('enrollments', function ($join) {
                $join->on('enrollments.id', '=', 'invoice_items.reference_id')
                    ->where('invoice_items.reference_type', '=', Enrollment::class);
            })
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->join('course_packages', 'course_packages.id', '=', 'enrollments.course_package_id')
            ->whereNotIn('invoices.status', [InvoiceStatus::CANCELLED, InvoiceStatus::VOID]);

        if ($dateFrom !== null) {
            $query->whereDate('invoices.invoice_date', '>=', $dateFrom);
        }
        if ($dateTo !== null) {
            $query->whereDate('invoices.invoice_date', '<=', $dateTo);
        }

        return $query
            ->select(
                'course_packages.id as course_package_id',
                'course_packages.name as package_name',
                DB::raw('COUNT(DISTINCT enrollments.student_id) as students'),
                DB::raw('SUM(invoice_items.total) as revenue'),
            )
            ->groupBy('course_packages.id', 'course_packages.name')
            ->orderByDesc('revenue')
            ->get()
            ->map(fn ($row) => [
                'course_package_id' => (int) $row->course_package_id,
                'package_name' => $row->package_name,
                'students' => (int) $row->students,
                'revenue' => (float) $row->revenue,
            ])
            ->all();
    }

    /**
     * @return list<array{class_id: int, class_name: string, teacher: ?string, capacity: ?int, students: int, revenue: float}>
     */
    public function classReport(): array
    {
        $enrollmentCounts = DB::table('enrollments')
            ->where('status', '!=', Enrollment::STATUS_DROPPED)
            ->select('class_id', DB::raw('COUNT(*) as students'))
            ->groupBy('class_id')
            ->pluck('students', 'class_id');

        $revenue = DB::table('invoice_items')
            ->join('enrollments', function ($join) {
                $join->on('enrollments.id', '=', 'invoice_items.reference_id')
                    ->where('invoice_items.reference_type', '=', Enrollment::class);
            })
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->whereNotIn('invoices.status', [InvoiceStatus::CANCELLED, InvoiceStatus::VOID])
            ->select('enrollments.class_id', DB::raw('SUM(invoice_items.total) as revenue'))
            ->groupBy('enrollments.class_id')
            ->pluck('revenue', 'class_id');

        return DB::table('classes')
            ->leftJoin('teachers', 'teachers.id', '=', 'classes.teacher_id')
            ->select('classes.id as class_id', 'classes.name as class_name', 'teachers.name as teacher', 'classes.capacity as capacity')
            ->whereNull('classes.deleted_at')
            ->orderBy('classes.name')
            ->get()
            ->map(fn ($row) => [
                'class_id' => (int) $row->class_id,
                'class_name' => $row->class_name,
                'teacher' => $row->teacher,
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
