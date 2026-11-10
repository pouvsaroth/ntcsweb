<?php

declare(strict_types=1);

namespace App\Support\Academic;

use App\Models\ClassSchedule;
use App\Models\SchoolClass;
use App\Models\TeacherSubstitution;
use Illuminate\Support\Carbon;

/**
 * A class is "not held" on a day when HRM > Teacher > Substitute has a row
 * for it with no substitute (teacher away, nobody covers), for the whole day
 * or for every session the class has that day. Then no student attendance is
 * needed: no reminder, and the day does not lock the next class day.
 * If only some of the day's sessions are not held, attendance is still taken.
 */
final class ClassNotHeld
{
    public static function on(SchoolClass $class, string $date): bool
    {
        $ymd = Carbon::parse($date)->toDateString();

        $rows = TeacherSubstitution::query()
            ->where('class_id', $class->getKey())
            ->whereDate('date', $ymd)
            ->whereNull('substitute_staff_id')
            ->get(['start_time', 'end_time']);

        if ($rows->isEmpty()) {
            return false;
        }
        if ($rows->contains(fn (TeacherSubstitution $r) => $r->start_time === null)) {
            return true;
        }

        $dow = Carbon::parse($ymd)->dayOfWeekIso;
        $sessions = $class->schedules()->get()->filter(fn (ClassSchedule $s) => (int) $s->day_of_week === $dow);
        if ($sessions->isEmpty()) {
            return true;
        }

        $hhmm = fn ($t) => substr((string) $t, 0, 5);

        // Each session must be fully inside a not-held row's time.
        return $sessions->every(fn (ClassSchedule $s) => $rows->contains(
            fn (TeacherSubstitution $r) => $hhmm($r->start_time) <= $hhmm($s->start_time) && $hhmm($r->end_time) >= $hhmm($s->end_time),
        ));
    }
}
