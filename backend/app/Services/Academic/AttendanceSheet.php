<?php

declare(strict_types=1);

namespace App\Services\Academic;

use App\Models\AttendanceRecord;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Services\Pdf\BrowsershotRenderer;
use App\Services\Pdf\KhmerFont;
use App\Services\Pdf\PdfImageEncoder;
use App\Support\Academic\AttendanceStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Student Attendance > Attendance Sheet: one class over a date range, one
 * row per student and one column per day a student on the sheet has a
 * mark. Each cell is a mark — ✓ present, A absent, P permission, the late
 * minutes (e.g. "30") when late, blank when not taken, "–" before the
 * student's start study date (not counted) — then totals.
 * Shown on screen, and downloaded as Excel or PDF (the image is drawn in
 * the browser from the same data).
 */
final class AttendanceSheet
{
    public const PRESENT = '✓';

    public const ABSENT = 'A';

    public const PERMISSION = 'P';

    /** Late with no minutes entered. */
    public const LATE = 'L';

    /** A day before the student's start study date: not counted. */
    public const NOT_STARTED = '–';

    public function __construct(
        private readonly TenantContext $context,
        private readonly BrowsershotRenderer $renderer,
    ) {}

    /**
     * `$statuses`: null = Studying only, [] = every status, or a list of
     * enrollment statuses — same as AttendanceService::summarize().
     *
     * @param  list<string>|null  $statuses
     * @return array{
     *     class: array{id:int, name:string},
     *     date_from: string, date_to: string,
     *     dates: list<string>,
     *     students: list<array{
     *         enrollment_id:int, status:string,
     *         student: array{id:int, student_code:string|null, name:string},
     *         table: string|null,
     *         marks: array<string, string>,
     *         totals: array{present:int, late:int, absent:int, permission:int, late_minutes:int},
     *     }>,
     * }
     */
    public function build(SchoolClass $class, string $dateFrom, string $dateTo, ?array $statuses = null): array
    {
        $classId = (int) $class->getKey();

        $records = AttendanceRecord::query()
            ->where('class_id', $classId)
            ->whereDate('date', '>=', $dateFrom)
            ->whereDate('date', '<=', $dateTo)
            ->get();

        // Everyone in the class now, plus anyone with attendance in it in
        // the range (they have since moved class) — narrowed by status.
        $enrollments = Enrollment::query()
            ->with(['student', 'table'])
            ->where(fn (Builder $query) => $query
                ->where('class_id', $classId)
                ->orWhereIn('id', $records->pluck('enrollment_id')->unique()->values()))
            ->when($statuses === null, fn ($query) => $query->active())
            ->when($statuses !== null && $statuses !== [], fn ($query) => $query->whereIn('status', $statuses))
            ->get()
            ->filter(fn (Enrollment $enrollment) => $enrollment->student !== null)
            ->sortBy(fn (Enrollment $enrollment) => [
                // Seat order for those still in the class, then by name.
                (int) $enrollment->class_id === $classId && $enrollment->table !== null ? 0 : 1,
                (int) $enrollment->class_id === $classId ? ($enrollment->table->sort_order ?? 0) : 0,
                $enrollment->student->fullName(),
            ])
            ->values();

        $dates = $records->map(fn (AttendanceRecord $record) => $record->date->toDateString())->unique()->sort()->values()->all();
        $recordsByEnrollment = $records->groupBy('enrollment_id');

        $students = $enrollments->map(function (Enrollment $enrollment) use ($recordsByEnrollment, $dates) {
            $marks = [];
            $totals = ['present' => 0, 'late' => 0, 'absent' => 0, 'permission' => 0, 'late_minutes' => 0];
            $startOn = ($enrollment->start_study_on ?? $enrollment->enrolled_at)?->toDateString();

            // Days before they start studying: "–", and any mark on them is not counted.
            foreach ($dates as $date) {
                if ($startOn !== null && $date < $startOn) {
                    $marks[$date] = self::NOT_STARTED;
                }
            }

            foreach ($recordsByEnrollment->get($enrollment->id, collect()) as $record) {
                $date = $record->date->toDateString();
                if ($startOn !== null && $date < $startOn) {
                    continue;
                }
                $marks[$date] = self::mark($record->status, $record->late_minutes);

                match ($record->status) {
                    AttendanceStatus::ABSENT => $totals['absent']++,
                    AttendanceStatus::EXCUSED => $totals['permission']++,
                    AttendanceStatus::LATE => $totals['late']++,
                    default => $totals['present']++,
                };
                if ($record->status === AttendanceStatus::LATE) {
                    $totals['late_minutes'] += (int) ($record->late_minutes ?? 0);
                }
            }

            return [
                'enrollment_id' => $enrollment->id,
                'status' => $enrollment->status,
                'student' => [
                    'id' => $enrollment->student->id,
                    'student_code' => $enrollment->student->student_code,
                    'name' => $enrollment->student->fullName(),
                ],
                'table' => $enrollment->table?->name,
                'start_study_on' => $startOn,
                'marks' => $marks,
                'totals' => $totals,
            ];
        })->values();

        // Only days where at least one student on the sheet has a real mark
        // (not just "–" or blank, e.g. everyone had not started yet, or the
        // only marks were for students left out by the status filter).
        $dates = array_values(array_filter($dates, fn (string $date) => $students->contains(
            fn (array $row) => isset($row['marks'][$date]) && $row['marks'][$date] !== self::NOT_STARTED,
        )));
        $students = $students->map(function (array $row) use ($dates) {
            $row['marks'] = array_intersect_key($row['marks'], array_flip($dates));

            return $row;
        })->all();

        return [
            'class' => ['id' => $classId, 'name' => (string) $class->name],
            'date_from' => Carbon::parse($dateFrom)->toDateString(),
            'date_to' => Carbon::parse($dateTo)->toDateString(),
            'dates' => $dates,
            'students' => $students,
        ];
    }

    public static function mark(string $status, ?int $lateMinutes): string
    {
        return match ($status) {
            AttendanceStatus::ABSENT => self::ABSENT,
            AttendanceStatus::EXCUSED => self::PERMISSION,
            AttendanceStatus::LATE => $lateMinutes ? (string) $lateMinutes : self::LATE,
            default => self::PRESENT,
        };
    }

    /** The sheet as an .xlsx file (binary string). */
    public function excel(array $sheet): string
    {
        $tenant = $this->context->getOrFail();
        $dates = $sheet['dates'];
        $dayCount = count($dates);

        $center = (new Style)->setCellAlignment(CellAlignment::CENTER);
        $head = (new Style)->setFontBold()->setBackgroundColor('F3F4F6')->setCellAlignment(CellAlignment::CENTER)->setShouldWrapText();
        $markStyles = [
            self::ABSENT => (new Style)->setCellAlignment(CellAlignment::CENTER)->setFontBold()->setFontColor('B91C1C')->setBackgroundColor('FEE2E2'),
            self::PERMISSION => (new Style)->setCellAlignment(CellAlignment::CENTER)->setFontBold()->setFontColor('1D4ED8')->setBackgroundColor('DBEAFE'),
            'late' => (new Style)->setCellAlignment(CellAlignment::CENTER)->setFontBold()->setFontColor('92400E')->setBackgroundColor('FEF3C7'),
            self::PRESENT => (new Style)->setCellAlignment(CellAlignment::CENTER)->setFontColor('15803D'),
            self::NOT_STARTED => (new Style)->setCellAlignment(CellAlignment::CENTER)->setFontColor('9CA3AF')->setBackgroundColor('F3F4F6'),
        ];

        $options = new Options;
        $options->setColumnWidth(5, 1);
        $options->setColumnWidth(12, 2);
        $options->setColumnWidth(28, 3);
        if ($dayCount > 0) {
            $options->setColumnWidthForRange(6, 4, 3 + $dayCount);
        }
        $options->setColumnWidthForRange(10, 4 + $dayCount, 8 + $dayCount);

        $path = tempnam(sys_get_temp_dir(), 'attendance');
        $writer = new Writer($options);
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Attendance');

        $writer->addRow(Row::fromValues([$tenant->name], (new Style)->setFontBold()->setFontSize(14)));
        $writer->addRow(Row::fromValues([__('attendance_sheet.title').' · '.$sheet['class']['name'].' · '.$this->period($sheet)], (new Style)->setFontBold()));
        $writer->addRow(Row::fromValues([$this->legend()]));
        $writer->addRow(Row::fromValues([]));

        $writer->addRow(Row::fromValues(array_merge(
            [__('attendance_sheet.no'), __('attendance_sheet.id'), __('attendance_sheet.student')],
            array_map(fn (string $date) => Carbon::parse($date)->format('d-m'), $dates),
            $this->totalLabels(),
        ), $head));

        foreach ($sheet['students'] as $i => $row) {
            $cells = [
                Cell::fromValue($i + 1, $center),
                Cell::fromValue($row['student']['student_code'] ?? ''),
                Cell::fromValue($row['student']['name']),
            ];
            foreach ($dates as $date) {
                $mark = $row['marks'][$date] ?? '';
                $style = $markStyles[$mark] ?? ($mark !== '' ? $markStyles['late'] : $center);
                $cells[] = Cell::fromValue(is_numeric($mark) ? (int) $mark : $mark, $style);
            }
            foreach ($row['totals'] as $value) {
                $cells[] = Cell::fromValue($value, $center);
            }
            $writer->addRow(new Row($cells));
        }

        $writer->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    /** The sheet as a landscape A4 PDF (binary string). */
    public function pdf(array $sheet): string
    {
        $tenant = $this->context->getOrFail();

        $html = view('pdf.attendance-sheet', [
            'tenant' => $tenant,
            'logoDataUri' => PdfImageEncoder::dataUri($tenant->logoPath()),
            'khmerFontRegular' => KhmerFont::dataUri('Regular'),
            'khmerFontBold' => KhmerFont::dataUri('Bold'),
            'generatedAt' => now($tenant->timezone ?: 'Asia/Phnom_Penh')->format('d-m-Y H:i'),
            'sheet' => $sheet,
            'period' => $this->period($sheet),
            'legend' => $this->legend(),
            'totalLabels' => $this->totalLabels(),
        ])->render();

        return $this->renderer->render($html, 'A4', 10, landscape: true);
    }

    public static function fileName(array $sheet, string $extension): string
    {
        $slug = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $sheet['class']['name']), '-') ?: 'class';

        return "attendance-{$slug}-{$sheet['date_from']}-to-{$sheet['date_to']}.{$extension}";
    }

    private function period(array $sheet): string
    {
        return Carbon::parse($sheet['date_from'])->format('d-m-Y').' – '.Carbon::parse($sheet['date_to'])->format('d-m-Y');
    }

    private function legend(): string
    {
        return implode('  ·  ', [
            self::PRESENT.' = '.__('attendance_sheet.present'),
            self::ABSENT.' = '.__('attendance_sheet.absent'),
            self::PERMISSION.' = '.__('attendance_sheet.permission'),
            '30 = '.__('attendance_sheet.late_minutes_example'),
            self::NOT_STARTED.' = '.__('attendance_sheet.not_started'),
        ]);
    }

    /** @return list<string> */
    private function totalLabels(): array
    {
        return [
            __('attendance_sheet.total_present'),
            __('attendance_sheet.total_late'),
            __('attendance_sheet.total_absent'),
            __('attendance_sheet.total_permission'),
            __('attendance_sheet.total_late_minutes'),
        ];
    }
}
