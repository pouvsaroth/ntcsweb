<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Meeting;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Front Office > Meeting arrangement > Attendance report: for the meetings
 * in a date range (cancelled ones left out), how often each staff member was
 * present, late, absent or excused — plus every meeting with each person's
 * mark, guests included, for the Excel export.
 */
final class MeetingAttendanceController extends Controller
{
    private const MAX_DAYS = 366;

    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success($this->build($request));
    }

    public function excel(Request $request): Response
    {
        $report = $this->build($request);
        $bold = (new Style)->setFontBold();
        $col = fn (string $k) => __('meeting_attendance.columns.'.$k);

        $path = tempnam(sys_get_temp_dir(), 'meeting-attendance');
        $writer = new Writer;
        $writer->openToFile($path);

        $writer->getCurrentSheet()->setName(mb_substr(__('meeting_attendance.summary'), 0, 31));
        $writer->addRow(Row::fromValues([$this->context->get()?->name ?? '', __('meeting_attendance.title'), $this->period($report)], $bold));
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(array_map($col, ['no', 'name', 'employee_code', 'meetings', 'present', 'late', 'absent', 'excused', 'not_marked', 'attended_percent']), $bold));
        foreach ($report['summary'] as $i => $s) {
            $writer->addRow(Row::fromValues([$i + 1, $s['name'], $s['employee_code'], $s['meetings'], $s['present'], $s['late'], $s['absent'], $s['excused'], $s['not_marked'], $s['attended_percent']]));
        }

        $writer->addNewSheetAndMakeItCurrent()->setName(mb_substr(__('meeting_attendance.meetings'), 0, 31));
        $writer->addRow(Row::fromValues(array_map($col, ['no', 'date', 'time', 'meeting', 'name', 'type', 'attendance']), $bold));
        $n = 0;
        foreach ($report['meetings'] as $m) {
            foreach ($m['people'] as $p) {
                $writer->addRow(Row::fromValues([
                    ++$n,
                    Carbon::parse($m['date'])->format('d-m-Y'),
                    $m['time'],
                    $m['title'],
                    $p['name'],
                    __('meeting_attendance.'.($p['guest'] ? 'guest' : 'staff')),
                    $p['attendance'] !== null ? __('meeting_attendance.states.'.$p['attendance']) : __('meeting_attendance.states.not_marked'),
                ]));
            }
        }

        $writer->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return response($bytes, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="meeting-attendance-'.$report['from'].'-to-'.$report['to'].'.xlsx"',
        ]);
    }

    /** @return array{from: string, to: string, summary: list<array<string, mixed>>, meetings: list<array<string, mixed>>} */
    private function build(Request $request): array
    {
        $this->authorize('viewAny', Meeting::class);

        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);
        if (Carbon::parse($data['from'])->diffInDays(Carbon::parse($data['to'])) >= self::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => __('Pick a range of :days days or less.', ['days' => self::MAX_DAYS])]);
        }
        $from = Carbon::parse($data['from'])->toDateString();
        $to = Carbon::parse($data['to'])->toDateString();

        $meetings = Meeting::query()->with('participants')
            ->where('status', '!=', 'cancelled')
            ->whereBetween('meeting_date', [$from, $to])
            ->orderBy('meeting_date')->orderBy('start_time')
            ->get();

        $summary = [];
        $list = [];
        foreach ($meetings as $meeting) {
            $people = [];
            foreach ($meeting->participants as $staff) {
                $mark = $staff->pivot?->attendance;
                $people[] = ['name' => $staff->fullName(), 'guest' => false, 'attendance' => $mark];

                $row = $summary[$staff->id] ??= [
                    'staff_id' => $staff->id, 'name' => $staff->fullName(), 'employee_code' => $staff->employee_code,
                    'meetings' => 0, 'present' => 0, 'late' => 0, 'absent' => 0, 'excused' => 0, 'not_marked' => 0, 'attended_percent' => null,
                ];
                $row['meetings']++;
                $row[$mark ?? 'not_marked']++;
                $summary[$staff->id] = $row;
            }
            foreach ($meeting->guestNames() as $guest) {
                $people[] = ['name' => $guest, 'guest' => true, 'attendance' => $meeting->guest_attendance[$guest] ?? null];
            }

            $list[] = [
                'id' => $meeting->id,
                'date' => $meeting->meeting_date->toDateString(),
                'time' => substr((string) $meeting->start_time, 0, 5).($meeting->end_time ? '–'.substr((string) $meeting->end_time, 0, 5) : ''),
                'title' => $meeting->title,
                'people' => $people,
            ];
        }

        // Attended = present or late, out of the meetings that were marked.
        $summary = array_map(function (array $row) {
            $marked = $row['meetings'] - $row['not_marked'];
            $row['attended_percent'] = $marked > 0 ? round(($row['present'] + $row['late']) * 100 / $marked, 1) : null;

            return $row;
        }, array_values($summary));
        usort($summary, fn (array $a, array $b) => strcasecmp($a['name'], $b['name']));

        return ['from' => $from, 'to' => $to, 'summary' => $summary, 'meetings' => $list];
    }

    private function period(array $report): string
    {
        return Carbon::parse($report['from'])->format('d-m-Y').' – '.Carbon::parse($report['to'])->format('d-m-Y');
    }
}
