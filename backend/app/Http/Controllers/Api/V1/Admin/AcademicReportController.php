<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\EnrollmentResource;
use App\Http\Responses\ApiResponse;
use App\Models\Enrollment;
use App\Models\Student;
use App\Services\Academic\AcademicReportService;
use App\Support\Authorization\Permissions;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Collection;

/**
 * Enrollment/Program-Revenue/Package-Sales/Class/Student-Financial reports.
 * Every figure comes from AcademicReportService's own SQL aggregation —
 * mirrors AssetReportController/AccountingReportController's shape,
 * including the plain-fputcsv `?format=csv` export (no new export library).
 */
final class AcademicReportController extends Controller
{
    public function __construct(private readonly AcademicReportService $reports) {}

    public function enrollments(Request $request): JsonResponse|HttpResponse
    {
        $this->authorizeView($request);

        $query = ApiQuery::for(Enrollment::query()->with(['student', 'schoolClass', 'academicProgram', 'studyMode', 'coursePackage']), $request)
            ->filterable(['status', 'academic_program_id', 'study_mode_id', 'class_id', 'course_package_id']);

        if ($request->string('format')->toString() === 'csv') {
            $rows = $query->build()->get();

            return $this->csv('enrollment-report', ['Student', 'Program', 'Study Mode', 'Class', 'Package', 'Enrolled', 'Status', 'Fee'], $rows->map(fn (Enrollment $e) => [
                $e->student?->auditDisplayName(), $e->academicProgram?->name, $e->studyMode?->name, $e->schoolClass?->name,
                $e->coursePackage?->name, (string) $e->enrolled_at, $e->status, number_format((float) $e->fee, 2, '.', ''),
            ]));
        }

        return ApiResponse::success(EnrollmentResource::collection($query->sortable(['enrolled_at'], default: '-enrolled_at')->paginate()));
    }

    public function programRevenue(Request $request): JsonResponse|HttpResponse
    {
        $this->authorizeView($request);
        [$dateFrom, $dateTo] = $this->range($request);
        $rows = $this->reports->programRevenue($dateFrom, $dateTo);

        if ($request->string('format')->toString() === 'csv') {
            return $this->csv('program-revenue-report', ['Program', 'Enrollments', 'Revenue'], collect($rows)->map(
                fn (array $row) => [$row['program_name'], $row['enrollment_count'], number_format($row['revenue'], 2, '.', '')]
            ));
        }

        return ApiResponse::success($rows);
    }

    public function packageSales(Request $request): JsonResponse|HttpResponse
    {
        $this->authorizeView($request);
        [$dateFrom, $dateTo] = $this->range($request);
        $rows = $this->reports->packageSales($dateFrom, $dateTo);

        if ($request->string('format')->toString() === 'csv') {
            return $this->csv('package-sales-report', ['Package', 'Students', 'Revenue'], collect($rows)->map(
                fn (array $row) => [$row['package_name'], $row['students'], number_format($row['revenue'], 2, '.', '')]
            ));
        }

        return ApiResponse::success($rows);
    }

    public function classReport(Request $request): JsonResponse|HttpResponse
    {
        $this->authorizeView($request);
        $rows = $this->reports->classReport();

        if ($request->string('format')->toString() === 'csv') {
            return $this->csv('class-report', ['Class', 'Teacher', 'Capacity', 'Students', 'Revenue'], collect($rows)->map(
                fn (array $row) => [$row['class_name'], $row['teacher'], $row['capacity'], $row['students'], number_format($row['revenue'], 2, '.', '')]
            ));
        }

        return ApiResponse::success($rows);
    }

    public function studentFinancial(Request $request, Student $student): JsonResponse
    {
        $this->authorize('view', $student);

        return ApiResponse::success($this->reports->studentFinancialSummary($student));
    }

    private function authorizeView(Request $request): void
    {
        abort_unless($request->user()?->hasPermission(Permissions::ACADEMIC_REPORTS_VIEW), 403);
    }

    /** @return array{0: ?string, 1: ?string} */
    private function range(Request $request): array
    {
        return [
            $request->filled('date_from') ? $request->string('date_from')->toString() : null,
            $request->filled('date_to') ? $request->string('date_to')->toString() : null,
        ];
    }

    private function csv(string $filename, array $headers, Collection $rows): HttpResponse
    {
        abort_unless(request()->user()?->hasPermission(Permissions::ACADEMIC_REPORTS_EXPORT), 403);

        $handle = fopen('php://temp', 'w+');
        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}-".now()->toDateString().'.csv"',
        ]);
    }
}
