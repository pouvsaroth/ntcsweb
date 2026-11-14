<?php

declare(strict_types=1);

namespace App\Services\Academic;

use App\Models\AcademicProgram;
use App\Models\CoursePackage;
use App\Models\Enrollment;
use App\Models\Student;
use App\Services\Billing\CurrencyConversionService;
use App\Support\Billing\InvoiceStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
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
    public function __construct(
        private readonly CurrencyConversionService $currency,
        private readonly TenantContext $context,
    ) {}

    /** The currency every revenue figure here is in. */
    public function schoolCurrency(): string
    {
        return $this->context->getOrFail()->default_currency;
    }

    /**
     * Invoice-line revenue per `$key`, in the school's currency: USD and KHR
     * invoices are each converted at the rate on their invoice date, never
     * added up raw.
     *
     * @param  Builder<Enrollment>  $query  already joined to invoice_items and invoices
     * @return array<int|string, float>
     */
    private function revenueBy(Builder $query, string $key): array
    {
        $tenant = $this->context->getOrFail();

        return $query
            ->select(
                DB::raw("{$key} as group_key"),
                'invoices.currency as currency',
                DB::raw('DATE(invoices.invoice_date) as tx_date'),
                DB::raw('SUM(invoice_items.total) as total'),
            )
            ->groupBy(DB::raw($key), 'invoices.currency', DB::raw('DATE(invoices.invoice_date)'))
            ->get()
            ->groupBy('group_key')
            ->map(fn ($rows) => $this->currency->sumConverted($rows, $tenant))
            ->all();
    }

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

        $revenue = $this->revenueBy(clone $query, 'enrollments.academic_program_id');
        $rows = $query
            ->select(
                'enrollments.academic_program_id',
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
                'revenue' => $revenue[$program->id] ?? 0.0,
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

        $revenue = $this->revenueBy(clone $query, 'enrollments.course_package_id');
        $rows = $query
            ->select(
                'enrollments.course_package_id',
                DB::raw('COUNT(DISTINCT enrollments.student_id) as students'),
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
                'revenue' => $revenue[$package->id] ?? 0.0,
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
        $revenue = $this->revenueBy(Enrollment::query()
            ->join('invoice_items', function ($join) {
                $join->on('invoice_items.reference_id', '=', 'enrollments.id')
                    ->where('invoice_items.reference_type', '=', Enrollment::class);
            })
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->whereNotIn('invoices.status', [InvoiceStatus::CANCELLED, InvoiceStatus::VOID]), 'enrollments.class_id');

        // `classes`, `class_teachers` and `staff` all live in the tenant
        // database now, so every assigned teacher/assistant's name is one
        // ordinary join, aggregated per class.
        $classes = DB::connection('tenant')->table('classes')
            ->leftJoin('class_teachers', 'class_teachers.class_id', '=', 'classes.id')
            ->leftJoin('staff', 'staff.id', '=', 'class_teachers.staff_id')
            ->select(
                'classes.id as class_id', 'classes.name as class_name', 'classes.capacity as capacity',
                DB::raw("string_agg(NULLIF(trim(concat(staff.first_name, ' ', staff.last_name)), ''), ', ' ORDER BY staff.first_name) as teacher_names"),
            )
            ->whereNull('classes.deleted_at')
            ->groupBy('classes.id', 'classes.name', 'classes.capacity')
            ->orderBy('classes.name')
            ->get();

        return $classes
            ->map(fn ($row) => [
                'class_id' => (int) $row->class_id,
                'class_name' => $row->class_name,
                'teacher' => $row->teacher_names,
                'capacity' => $row->capacity !== null ? (int) $row->capacity : null,
                'students' => (int) ($enrollmentCounts[$row->class_id] ?? 0),
                'revenue' => $revenue[$row->class_id] ?? 0.0,
            ])
            ->all();
    }

    /**
     * @return array{currency: string, total_invoiced: float, total_paid: float, balance: float}
     */
    public function studentFinancialSummary(Student $student): array
    {
        $rows = DB::connection('tenant')->table('invoices')
            ->where('student_id', $student->getKey())
            ->whereNotIn('status', [InvoiceStatus::CANCELLED, InvoiceStatus::VOID])
            // A STOPPED invoice's remaining balance was written off, not owed.
            ->select('currency', DB::raw('DATE(invoice_date) as tx_date'))
            ->selectRaw('COALESCE(SUM(total), 0) as total_invoiced, COALESCE(SUM(paid_amount), 0) as total_paid, COALESCE(SUM(CASE WHEN status = ? THEN 0 ELSE balance END), 0) as balance', [InvoiceStatus::STOPPED])
            ->groupBy('currency', DB::raw('DATE(invoice_date)'))
            ->get();

        // USD and KHR invoices converted to the school's currency, never added raw.
        $tenant = $this->context->getOrFail();
        $sum = fn (string $column) => $this->currency->sumConverted(
            $rows->map(fn ($r) => (object) ['currency' => $r->currency, 'tx_date' => $r->tx_date, 'total' => $r->{$column}]),
            $tenant,
        );

        return [
            'currency' => $tenant->default_currency,
            'total_invoiced' => $sum('total_invoiced'),
            'total_paid' => $sum('total_paid'),
            'balance' => $sum('balance'),
        ];
    }
}
