<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\ProfessionalDevelopment\TrainingReport;
use App\Support\Authorization\Permissions;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * HRM > Professional Development > Training reports
 * (docs/hrm/professional-development.md). `?from=&to=` (default: this
 * calendar year, at most 3 years); `?format=csv` downloads the per-staff
 * table exactly as shown.
 */
final class TrainingReportController extends Controller
{
    public function __construct(private readonly TrainingReport $report) {}

    public function index(Request $request): JsonResponse|Response
    {
        abort_unless($request->user()?->hasPermission(Permissions::PROFESSIONAL_DEVELOPMENT_VIEW), 403);

        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $from = $request->filled('from') ? CarbonImmutable::parse((string) $request->query('from')) : CarbonImmutable::now()->startOfYear();
        $to = $request->filled('to') ? CarbonImmutable::parse((string) $request->query('to')) : CarbonImmutable::now()->endOfYear();
        if ($from->addYears(3)->lt($to)) {
            $to = $from->addYears(3)->subDay();
        }

        $data = $this->report->build($from->startOfDay(), $to->startOfDay());

        if ($request->query('format') === 'csv') {
            return $this->csv($data);
        }

        return ApiResponse::success($data);
    }

    /** @param  array<string, mixed>  $data */
    private function csv(array $data): Response
    {
        $handle = fopen('php://temp', 'w+');
        fwrite($handle, "\xEF\xBB\xBF"); // Excel reads Khmer names as UTF-8.
        fputcsv($handle, ['Employee code', 'Name', 'Department', 'Teacher', 'Trainings attended', 'Hours', 'Certificates', 'Last training', 'Observations', 'Average rating']);
        foreach ($data['by_staff'] as $r) {
            fputcsv($handle, [
                $r['employee_code'], $r['name'], $r['department'], $r['is_teacher'] ? 'Yes' : 'No',
                $r['trainings'], $r['hours'], $r['certificates'],
                $r['last_training'] ? CarbonImmutable::parse($r['last_training'])->format('d-m-Y') : '',
                $r['observations'], $r['average_rating'] ?? '',
            ]);
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"training-report-{$data['from']}-to-{$data['to']}.csv\"",
        ]);
    }
}
