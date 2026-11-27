<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Academic\ClassSessionReport;
use App\Services\Pdf\BrowsershotRenderer;
use App\Services\Pdf\KhmerFont;
use App\Services\Pdf\PdfImageEncoder;
use App\Support\Authorization\Permissions;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * HRM > Teachers > Class sessions: each scheduled session and whether its
 * teacher taught it, was covered, it was not held or it was missed — with a
 * per-teacher summary. See ClassSessionReport and docs/hrm/teachers.md.
 */
final class ClassSessionController extends Controller
{
    public function __construct(
        private readonly ClassSessionReport $report,
        private readonly TenantContext $context,
        private readonly BrowsershotRenderer $renderer,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success($this->build($request));
    }

    public function excel(Request $request): Response
    {
        $report = $this->build($request);
        $bold = (new Style)->setFontBold();

        $path = tempnam(sys_get_temp_dir(), 'class-sessions');
        $writer = new Writer;
        $writer->openToFile($path);

        $writer->getCurrentSheet()->setName(mb_substr(__('class_sessions.summary'), 0, 31));
        $writer->addRow(Row::fromValues([$this->context->get()?->name ?? '', __('class_sessions.title'), $this->period($report)], $bold));
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(array_map(fn (string $k) => __('class_sessions.columns.'.$k), ['no', 'teacher', 'planned', 'taught', 'late', 'covered', 'not_held', 'missed', 'no_record', 'covering', 'taught_hours', 'taught_percent']), $bold));
        foreach ($report['summary'] as $i => $s) {
            $writer->addRow(Row::fromValues([$i + 1, $s['teacher'], $s['planned'], $s['taught'], $s['late'], $s['covered'], $s['not_held'], $s['missed'], $s['no_record'], $s['covering'], round($s['taught_minutes'] / 60, 2), $s['taught_percent']]));
        }

        $writer->addNewSheetAndMakeItCurrent()->setName(mb_substr(__('class_sessions.sessions'), 0, 31));
        $writer->addRow(Row::fromValues(array_map(fn (string $k) => __('class_sessions.columns.'.$k), ['no', 'date', 'time', 'class', 'subject', 'teacher', 'status', 'detail', 'check_in', 'late_minutes']), $bold));
        foreach ($report['rows'] as $i => $r) {
            $writer->addRow(Row::fromValues([
                $i + 1,
                Carbon::parse($r['date'])->format('d-m-Y'),
                $r['start_time'].'–'.$r['end_time'],
                $r['class'],
                $r['subject'],
                $r['teacher'] ?? '—',
                __('class_sessions.status.'.$r['status']),
                $this->detail($r),
                $r['check_in'],
                $r['late_minutes'] ?: null,
            ]));
        }

        $writer->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $this->download($bytes, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xlsx', $report);
    }

    public function pdf(Request $request): Response
    {
        $report = $this->build($request);
        $tenant = $this->context->getOrFail();
        $report['rows'] = array_map(fn (array $r) => $r + ['detail' => $this->detail($r)], $report['rows']);

        $html = view('pdf.class-sessions', [
            'tenant' => $tenant,
            'logoDataUri' => PdfImageEncoder::dataUri($tenant->logoPath()),
            'khmerFontRegular' => KhmerFont::dataUri('Regular'),
            'khmerFontBold' => KhmerFont::dataUri('Bold'),
            'generatedAt' => now($tenant->timezone ?: 'Asia/Phnom_Penh')->format('d-m-Y H:i'),
            'period' => $this->period($report),
            'report' => $report,
        ])->render();

        return $this->download($this->renderer->render($html, 'A4', 10, landscape: true), 'application/pdf', 'pdf', $report);
    }

    /** @return array<string, mixed> */
    private function build(Request $request): array
    {
        abort_unless($request->user()->hasPermission(Permissions::TEACHERS_VIEW), 403);

        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'staff_id' => ['nullable', 'integer'],
            'class_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(ClassSessionReport::STATUSES)],
        ]);
        if (Carbon::parse($data['from'])->diffInDays(Carbon::parse($data['to'])) >= ClassSessionReport::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => __('Pick a range of :days days or less.', ['days' => ClassSessionReport::MAX_DAYS])]);
        }

        return $this->report->build(
            $data['from'],
            $data['to'],
            isset($data['staff_id']) ? (int) $data['staff_id'] : null,
            isset($data['class_id']) ? (int) $data['class_id'] : null,
            $data['status'] ?? null,
        );
    }

    /** "Covered by X", "Covering for X", "On leave", … */
    private function detail(array $r): string
    {
        return match (true) {
            $r['status'] === ClassSessionReport::COVERED => __('class_sessions.covered_by', ['name' => $r['other_teacher']]),
            $r['status'] === ClassSessionReport::COVERING => __('class_sessions.covering_for', ['name' => $r['other_teacher']]),
            $r['why'] !== null => __('class_sessions.why.'.$r['why']),
            $r['status'] === ClassSessionReport::NO_RECORD => __('class_sessions.no_record_hint'),
            default => '',
        };
    }

    private function period(array $report): string
    {
        return Carbon::parse($report['from'])->format('d-m-Y').' – '.Carbon::parse($report['to'])->format('d-m-Y');
    }

    private function download(string $bytes, string $type, string $extension, array $report): Response
    {
        return response($bytes, 200, [
            'Content-Type' => $type,
            'Content-Disposition' => 'attachment; filename="class-sessions-'.$report['from'].'-to-'.$report['to'].'.'.$extension.'"',
        ]);
    }
}
