<?php

declare(strict_types=1);

namespace App\Services\StaffAttendance;

use App\Models\AttendanceApproval;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\Shift;
use App\Models\Staff;
use App\Models\StaffAttendance;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Staff attendance (HRM > Attendance & Time): check-in / check-out from the
 * app, HR's own entries and machine imports, and what each day comes to.
 *
 * Times: every request runs on the school's own clock (ResolveTenant sets the
 * PHP timezone to the tenant's), and times are stored in it — so shift times
 * ("08:00") and check-ins compare directly, with no conversion.
 *
 * A day with a check-in is stored (StaffAttendance) and its minutes worked
 * out when it changes: late = after the shift start beyond its allowance,
 * early leave = before the shift end beyond its allowance, overtime = past
 * the shift end. A day without one is worked out when asked (sheet()):
 * holiday, on approved leave, day off by the schedule, or absent.
 */
final class StaffAttendanceService
{
    /** How long after checking in a check-out still belongs to that day (covers overnight shifts). */
    private const OPEN_DAY_HOURS = 20;

    /** The school's timezone — see ResolveTenant. */
    public function timezone(): string
    {
        return date_default_timezone_get();
    }

    public function localNow(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone());
    }

    /** That staff member's month has been signed off (Attendance approval). */
    public function isLocked(int $staffId, string $date): bool
    {
        return AttendanceApproval::query()->where('staff_id', $staffId)->where('month', substr($date, 0, 7))->exists();
    }

    /** Refuses any change to a signed-off month. */
    public function assertOpen(int $staffId, string $date): void
    {
        if ($this->isLocked($staffId, $date)) {
            throw ValidationException::withMessages(['date' => __('The attendance for :month has been signed off and is locked.', ['month' => substr($date, 0, 7)])]);
        }
    }

    // --- Self service -------------------------------------------------------------

    public function checkIn(Staff $staff, ?float $latitude, ?float $longitude): StaffAttendance
    {
        $now = $this->localNow();

        $this->assertOpen($staff->id, $now->toDateString());

        return DB::connection('tenant')->transaction(function () use ($staff, $now, $latitude, $longitude) {
            $row = StaffAttendance::query()->where('staff_id', $staff->id)->whereDate('date', $now->toDateString())->lockForUpdate()->first();

            if ($row?->check_in_at !== null) {
                throw ValidationException::withMessages(['check_in' => __('You have already checked in today.')]);
            }

            $row ??= $this->newRow($staff, $now->startOfDay());
            $row->fill([
                'check_in_at' => $now,
                'check_in_source' => StaffAttendance::SOURCE_APP,
                'check_in_latitude' => $latitude,
                'check_in_longitude' => $longitude,
            ]);

            return $this->recalculate($row);
        });
    }

    public function checkOut(Staff $staff, ?float $latitude, ?float $longitude): StaffAttendance
    {
        $now = $this->localNow();

        return DB::connection('tenant')->transaction(function () use ($staff, $now, $latitude, $longitude) {
            // The open day — today's, or last night's on an overnight shift.
            $row = StaffAttendance::query()
                ->where('staff_id', $staff->id)
                ->whereNotNull('check_in_at')
                ->whereNull('check_out_at')
                ->where('check_in_at', '>=', $now->subHours(self::OPEN_DAY_HOURS))
                ->orderByDesc('check_in_at')
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                throw ValidationException::withMessages(['check_out' => __('Check in first.')]);
            }
            $this->assertOpen($staff->id, $row->date->toDateString());

            $row->fill([
                'check_out_at' => $now,
                'check_out_source' => StaffAttendance::SOURCE_APP,
                'check_out_latitude' => $latitude,
                'check_out_longitude' => $longitude,
            ]);

            return $this->recalculate($row);
        });
    }

    // --- HR entries and imports -----------------------------------------------------

    /**
     * Sets one day's check-in/out (local "HH:MM"; a check-out earlier than the
     * check-in is the next morning). Null leaves that side as it was; empty
     * string clears it.
     */
    public function record(Staff $staff, string $date, ?string $checkIn, ?string $checkOut, string $source, ?string $note, ?User $by): StaffAttendance
    {
        $day = CarbonImmutable::parse($date, $this->timezone())->startOfDay();
        $this->assertOpen($staff->id, $day->toDateString());

        return DB::connection('tenant')->transaction(function () use ($staff, $day, $checkIn, $checkOut, $source, $note, $by) {
            $row = StaffAttendance::query()->where('staff_id', $staff->id)->whereDate('date', $day->toDateString())->lockForUpdate()->first()
                ?? $this->newRow($staff, $day);

            if ($checkIn !== null) {
                $row->check_in_at = $checkIn === '' ? null : $this->at($day, $checkIn);
                $row->check_in_source = $checkIn === '' ? null : $source;
            }

            if ($checkOut !== null) {
                $out = $checkOut === '' ? null : $this->at($day, $checkOut);
                if ($out !== null && $row->check_in_at !== null && $out->lessThanOrEqualTo($row->check_in_at)) {
                    $out = $out->addDay();
                }
                $row->check_out_at = $out;
                $row->check_out_source = $checkOut === '' ? null : $source;
            }

            if ($row->check_in_at === null && $row->check_out_at !== null) {
                throw ValidationException::withMessages(['check_in' => __('A check-out needs a check-in.')]);
            }

            if ($note !== null) {
                $row->note = $note === '' ? null : $note;
            }
            $row->updated_by = $by?->getKey();

            return $this->recalculate($row);
        });
    }

    /**
     * A CSV from a fingerprint machine or a spreadsheet. Either one row per
     * punch (employee_code, date, time — or employee_code, datetime): each
     * day's first punch is the check-in, its last the check-out; or one row
     * per day (employee_code, date, check_in, check_out).
     *
     * @return array{days: int, rows: int, errors: list<string>}
     */
    public function import(string $csv, User $by): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($csv)) ?: [];
        $header = array_map(fn ($h) => strtolower(trim(str_replace([' ', '-'], '_', (string) $h))), str_getcsv((string) array_shift($lines)));
        $col = fn (string ...$names) => collect($names)->map(fn ($n) => array_search($n, $header, true))->first(fn ($i) => $i !== false);

        $codeCol = $col('employee_code', 'staff_code', 'code', 'id');
        $dateCol = $col('date');
        $timeCol = $col('time');
        $dateTimeCol = $col('datetime', 'date_time', 'timestamp', 'punch_time');
        $inCol = $col('check_in', 'in', 'time_in');
        $outCol = $col('check_out', 'out', 'time_out');

        if ($codeCol === null || ($dateCol === null && $dateTimeCol === null)) {
            throw ValidationException::withMessages(['file' => __('The file needs an employee_code column and a date (or datetime) column.')]);
        }

        $staffByCode = Staff::query()->get()->keyBy(fn (Staff $s) => strtolower((string) $s->employee_code));
        $days = [];
        $errors = [];

        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = array_map('trim', str_getcsv($line));
            $lineNo = $index + 2;
            $staff = $staffByCode->get(strtolower($cells[$codeCol] ?? ''));

            if ($staff === null) {
                $errors[] = __('Line :line: no staff with code ":code".', ['line' => $lineNo, 'code' => $cells[$codeCol] ?? '']);

                continue;
            }

            try {
                if ($dateTimeCol !== null || $timeCol !== null) {
                    $moment = $dateTimeCol !== null
                        ? $this->parseMoment($cells[$dateTimeCol] ?? '')
                        : $this->parseMoment(($cells[$dateCol] ?? '').' '.($cells[$timeCol] ?? ''));
                    $key = $staff->id.'|'.$moment->toDateString();
                    $days[$key]['staff'] = $staff;
                    $days[$key]['date'] = $moment->toDateString();
                    $days[$key]['punches'][] = $moment->format('H:i');
                } else {
                    $date = $this->parseMoment(($cells[$dateCol] ?? '').' 00:00')->toDateString();
                    $key = $staff->id.'|'.$date;
                    $days[$key] = ['staff' => $staff, 'date' => $date, 'in' => $cells[$inCol] ?? '', 'out' => $cells[$outCol] ?? ''];
                }
            } catch (\Throwable) {
                $errors[] = __('Line :line: the date or time could not be read.', ['line' => $lineNo]);
            }
        }

        foreach ($days as $day) {
            if (isset($day['punches'])) {
                sort($day['punches']);
                $in = $day['punches'][0];
                $out = count($day['punches']) > 1 ? end($day['punches']) : null;
            } else {
                $in = $day['in'] !== '' ? substr($day['in'], 0, 5) : null;
                $out = $day['out'] !== '' ? substr($day['out'], 0, 5) : null;
            }

            if ($in === null) {
                continue;
            }

            if ($this->isLocked($day['staff']->id, $day['date'])) {
                $errors[] = __(':code :date: that month is signed off — skipped.', ['code' => $day['staff']->employee_code, 'date' => $day['date']]);

                continue;
            }

            $this->record($day['staff'], $day['date'], $in, $out, StaffAttendance::SOURCE_IMPORT, null, $by);
        }

        return ['days' => count($days), 'rows' => count($lines), 'errors' => array_slice($errors, 0, 50)];
    }

    // --- Working out a day --------------------------------------------------------------

    /** Re-reads the shift's times against the check-in/out and saves the minutes and status. */
    public function recalculate(StaffAttendance $row): StaffAttendance
    {
        $shift = $row->shift_id !== null ? Shift::query()->find($row->shift_id) : null;
        $in = $row->check_in_at;
        $out = $row->check_out_at;

        $late = $early = $worked = $overtime = 0;

        if ($in !== null && $out !== null) {
            // That weekday's own unpaid break — see Shift::breakOn().
            $worked = max(0, (int) $in->diffInMinutes($out, false) - ($shift?->breakOn($row->date) ?? 0));
        }

        if ($shift !== null && $row->scheduled_start !== null && $row->scheduled_end !== null) {
            if ($in !== null) {
                $lateBy = (int) $row->scheduled_start->diffInMinutes($in, false);
                $late = $lateBy > $shift->late_grace_minutes ? $lateBy : 0;
            }
            if ($out !== null) {
                $earlyBy = (int) $out->diffInMinutes($row->scheduled_end, false);
                $early = $earlyBy > $shift->early_leave_grace_minutes ? $earlyBy : 0;
                $overtime = max(0, (int) $row->scheduled_end->diffInMinutes($out, false));
            }
        } elseif ($worked > 0) {
            // Working a day off or a holiday: all of it is extra.
            $overtime = $worked;
        }

        $row->fill([
            'late_minutes' => min($late, 65535),
            'early_leave_minutes' => min($early, 65535),
            'worked_minutes' => min($worked, 65535),
            'overtime_minutes' => min($overtime, 65535),
            'status' => match (true) {
                $in === null => StaffAttendance::STATUS_ABSENT,
                $out === null => StaffAttendance::STATUS_INCOMPLETE,
                $late > 0 => StaffAttendance::STATUS_LATE,
                $early > 0 => StaffAttendance::STATUS_EARLY_LEAVE,
                default => StaffAttendance::STATUS_PRESENT,
            },
        ]);
        $row->save();

        return $row;
    }

    /** Removes a day's record (HR), unless its month is signed off. */
    public function delete(StaffAttendance $row): void
    {
        $this->assertOpen($row->staff_id, $row->date->toDateString());
        $row->delete();
    }

    /** A new day row with its shift (if any) copied on from the staff member's schedule. */
    private function newRow(Staff $staff, CarbonImmutable $day): StaffAttendance
    {
        $shiftId = Holiday::covering($day) !== null ? null : WorkSchedule::forStaff($staff)?->shiftIdOn($day);
        $shift = $shiftId !== null ? Shift::query()->find($shiftId) : null;
        [$start, $end] = $shift !== null ? $this->scheduledTimes($shift, $day) : [null, null];

        return new StaffAttendance([
            'staff_id' => $staff->id,
            'date' => $day->toDateString(),
            'shift_id' => $shift?->id,
            'scheduled_start' => $start,
            'scheduled_end' => $end,
            'status' => StaffAttendance::STATUS_ABSENT,
        ]);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public function scheduledTimes(Shift $shift, CarbonInterface $day): array
    {
        $date = CarbonImmutable::parse($day->toDateString(), $this->timezone());
        // That weekday's own hours when the shift has a row for it — see Shift::timesOn().
        [$from, $to] = $shift->timesOn($date);
        $start = $this->at($date, $from);
        $end = $this->at($date, $to);

        return [$start, $end->lessThanOrEqualTo($start) ? $end->addDay() : $end];
    }

    private function at(CarbonImmutable $day, string $time): CarbonImmutable
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})/', $time, $m) || (int) $m[1] > 23 || (int) $m[2] > 59) {
            throw ValidationException::withMessages(['time' => __('":time" is not a time.', ['time' => $time])]);
        }

        return CarbonImmutable::parse($day->toDateString(), $this->timezone())->setTime((int) $m[1], (int) $m[2]);
    }

    private function parseMoment(string $value): CarbonImmutable
    {
        $value = trim($value);
        foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'd/m/Y H:i:s', 'd/m/Y H:i', 'd-m-Y H:i:s', 'd-m-Y H:i', 'Y/m/d H:i:s', 'Y/m/d H:i'] as $format) {
            try {
                $parsed = CarbonImmutable::createFromFormat($format, $value, $this->timezone());
            } catch (\Throwable) {
                continue;
            }
            if ($parsed !== false && $parsed !== null && $parsed->format($format) === $value) {
                return $parsed;
            }
        }

        throw new \InvalidArgumentException("Unreadable date: {$value}");
    }

    // --- The month sheet ---------------------------------------------------------------

    /**
     * Every given staff member's days from $from to $to: the stored row where
     * there is one, otherwise holiday / leave / day off / absent (or
     * `upcoming` for a day not over yet).
     *
     * @param  Collection<int, Staff>  $staff
     * @return list<array{staff: Staff, days: list<array<string, mixed>>, totals: array<string, int>}>
     */
    public function sheet(Collection $staff, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $ids = $staff->modelKeys();
        $rows = StaffAttendance::query()->whereIn('staff_id', $ids)->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString())
            ->get()->keyBy(fn (StaffAttendance $r) => $r->staff_id.'|'.$r->date->toDateString());
        $holidays = Holiday::query()->whereDate('end_date', '>=', $from->toDateString())->whereDate('start_date', '<=', $to->toDateString())->get();
        $leaves = LeaveRequest::query()->whereIn('staff_id', $ids)->where('status', LeaveRequest::STATUS_APPROVED)
            ->whereDate('to_date', '>=', $from->toDateString())->whereDate('from_date', '<=', $to->toDateString())->get()->groupBy('staff_id');
        $schedules = WorkSchedule::query()->get()->keyBy('id');
        $default = $schedules->firstWhere('is_default', true);
        $shifts = Shift::query()->with('days')->get()->keyBy('id');
        $now = $this->localNow();
        // Signed off for the month this range starts in (the sheet is always one month).
        $locked = AttendanceApproval::query()->whereIn('staff_id', $ids)->where('month', $from->format('Y-m'))->pluck('staff_id');

        $result = [];
        foreach ($staff as $member) {
            $schedule = $schedules->get($member->work_schedule_id) ?? $default;
            $days = [];
            $totals = array_fill_keys(['present', 'late', 'early_leave', 'incomplete', 'absent', 'holiday', 'leave', 'off', 'late_minutes', 'early_leave_minutes', 'worked_minutes', 'overtime_minutes'], 0);

            for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
                $date = $day->toDateString();
                $row = $rows->get($member->id.'|'.$date);
                $shift = $schedule?->shiftIdOn($day) ? $shifts->get($schedule->shiftIdOn($day)) : null;

                if ($row !== null) {
                    $entry = [
                        'date' => $date,
                        'status' => $row->status,
                        'id' => $row->id,
                        'check_in_at' => $row->check_in_at?->toIso8601String(),
                        'check_out_at' => $row->check_out_at?->toIso8601String(),
                        'late_minutes' => $row->late_minutes,
                        'early_leave_minutes' => $row->early_leave_minutes,
                        'worked_minutes' => $row->worked_minutes,
                        'overtime_minutes' => $row->overtime_minutes,
                    ];
                    foreach (['late_minutes', 'early_leave_minutes', 'worked_minutes', 'overtime_minutes'] as $minutes) {
                        $totals[$minutes] += $row->{$minutes};
                    }
                } else {
                    $status = match (true) {
                        $member->hire_date !== null && $day->lessThan(CarbonImmutable::parse($member->hire_date->toDateString(), $this->timezone())) => 'none',
                        $holidays->contains(fn (Holiday $h) => $h->start_date->toDateString() <= $date && $h->end_date->toDateString() >= $date) => StaffAttendance::STATUS_HOLIDAY,
                        ($leaves->get($member->id) ?? collect())->contains(fn (LeaveRequest $l) => $l->from_date->toDateString() <= $date && $l->to_date->toDateString() >= $date) => StaffAttendance::STATUS_LEAVE,
                        $shift === null => StaffAttendance::STATUS_OFF,
                        $this->notOverYet($shift, $day, $now) => 'upcoming',
                        default => StaffAttendance::STATUS_ABSENT,
                    };
                    $entry = ['date' => $date, 'status' => $status, 'id' => null];
                }

                $entry['shift'] = $shift !== null ? ['id' => $shift->id, 'name' => $shift->name, 'color' => $shift->color] : null;
                if (array_key_exists($entry['status'], $totals)) {
                    $totals[$entry['status']]++;
                }
                $days[] = $entry;
            }

            $result[] = ['staff' => $member, 'days' => $days, 'totals' => $totals, 'locked' => $locked->contains($member->id)];
        }

        return $result;
    }

    /** Still before the shift's start (+ allowance) — nobody's absent yet. */
    private function notOverYet(Shift $shift, CarbonImmutable $day, CarbonImmutable $now): bool
    {
        [$start] = $this->scheduledTimes($shift, $day);

        return $now->lessThan($start->addMinutes($shift->late_grace_minutes));
    }
}
