<?php

declare(strict_types=1);

namespace App\Services\Academic;

use App\Models\ClassSchedule;
use App\Models\SchoolClass;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The timetable (docs/academics/timetable.md) = the weekly times of the
 * upcoming and active classes. A time's teachers are its own teacher, or else
 * the class's teachers; its room is its own room, or else the class's room.
 */
final class TimetableService
{
    public const LIVE = [SchoolClass::STATUS_UPCOMING, SchoolClass::STATUS_ACTIVE];

    /**
     * Every live time, as the timetable shows it.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function slots(?callable $scope = null): Collection
    {
        $query = ClassSchedule::query()->with(['schoolClass.classroom:id,name', 'schoolClass.academicYear:id,name', 'book:id,title', 'staff', 'classroom:id,name'])
            ->whereHas('schoolClass', fn ($q) => $q->whereIn('status', self::LIVE))
            ->orderBy('day_of_week')->orderBy('start_time');
        if ($scope) {
            $scope($query);
        }
        $rows = $query->get();

        $classIds = $rows->pluck('class_id')->unique()->values()->all();
        $teachers = $classIds === [] ? collect() : DB::connection('tenant')->table('class_teachers as ct')
            ->join('staff as s', 's.id', '=', 'ct.staff_id')
            ->whereIn('ct.class_id', $classIds)->where('ct.role', SchoolClass::ROLE_TEACHER)->whereNull('s.deleted_at')
            ->get(['ct.class_id', 's.id', 's.first_name', 's.last_name'])
            ->groupBy('class_id');

        return $rows->map(function (ClassSchedule $s) use ($teachers) {
            $class = $s->schoolClass;
            $own = $s->staff;
            $classTeachers = $teachers->get($s->class_id, collect());
            $teacherList = $own
                ? [['id' => $own->id, 'name' => $own->fullName()]]
                : $classTeachers->map(fn ($t) => ['id' => (int) $t->id, 'name' => trim("{$t->first_name} {$t->last_name}")])->values()->all();
            $roomId = $s->classroom_id ?? $class?->classroom_id;
            $roomName = $s->classroom?->name ?? $class?->classroom?->name;

            return [
                'id' => $s->id,
                'class_id' => $s->class_id,
                'class' => $class?->name,
                'academic_year' => $class?->academicYear?->name,
                'day_of_week' => (int) $s->day_of_week,
                'start_time' => substr((string) $s->start_time, 0, 5),
                'end_time' => substr((string) $s->end_time, 0, 5),
                'book_id' => $s->book_id,
                'subject' => $s->book?->title,
                'staff_id' => $s->staff_id,
                'teachers' => $teacherList,
                'teacher_from_class' => $own === null,
                'classroom_id' => $s->classroom_id,
                'room_id' => $roomId,
                'room' => $roomName,
                'room_from_class' => $s->classroom_id === null,
                'note' => $s->note,
            ];
        })->values();
    }

    /**
     * Times that overlap: one class twice, one teacher in two places, one room
     * used twice — and a teacher's weekly time off teaching.
     *
     * @param  Collection<int, array<string, mixed>>|null  $slots
     * @return list<array<string, mixed>>
     */
    public function conflicts(?Collection $slots = null): array
    {
        $slots ??= $this->slots();
        $out = [];
        $byDay = $slots->groupBy('day_of_week');

        foreach ($byDay as $day => $daySlots) {
            $list = $daySlots->values()->all();
            $n = count($list);
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $a = $list[$i];
                    $b = $list[$j];
                    if (! $this->overlaps($a['start_time'], $a['end_time'], $b['start_time'], $b['end_time'])) {
                        continue;
                    }
                    if ($a['class_id'] === $b['class_id']) {
                        $out[] = $this->conflict('class', (int) $day, $a, $b, $a['class']);
                    }
                    $common = array_intersect(array_column($a['teachers'], 'id'), array_column($b['teachers'], 'id'));
                    foreach ($common as $teacherId) {
                        $name = collect($a['teachers'])->firstWhere('id', $teacherId)['name'] ?? null;
                        $out[] = $this->conflict('teacher', (int) $day, $a, $b, $name, (int) $teacherId);
                    }
                    if ($a['room_id'] !== null && $a['room_id'] === $b['room_id'] && $a['class_id'] !== $b['class_id']) {
                        $out[] = $this->conflict('room', (int) $day, $a, $b, $a['room']);
                    }
                }
            }
        }

        // A teacher's weekly time off teaching (HRM > Teacher > Availability).
        $off = DB::connection('tenant')->table('teacher_unavailabilities')->where('kind', 'weekly')->get();
        foreach ($off as $u) {
            foreach ($slots as $s) {
                if ((int) $s['day_of_week'] !== (int) $u->day_of_week || ! in_array((int) $u->staff_id, array_column($s['teachers'], 'id'), true)) {
                    continue;
                }
                if ($u->start_time === null || $this->overlaps($s['start_time'], $s['end_time'], (string) $u->start_time, (string) $u->end_time)) {
                    $name = collect($s['teachers'])->firstWhere('id', (int) $u->staff_id)['name'] ?? null;
                    $out[] = [
                        'type' => 'unavailable', 'day_of_week' => (int) $s['day_of_week'], 'who' => $name, 'staff_id' => (int) $u->staff_id,
                        'slots' => [$this->brief($s)], 'reason' => $u->reason,
                    ];
                }
            }
        }

        return $out;
    }

    public function overlaps(string $aStart, string $aEnd, string $bStart, string $bEnd): bool
    {
        return substr($aStart, 0, 5) < substr($bEnd, 0, 5) && substr($bStart, 0, 5) < substr($aEnd, 0, 5);
    }

    /** @param array<string, mixed> $a @param array<string, mixed> $b @return array<string, mixed> */
    private function conflict(string $type, int $day, array $a, array $b, ?string $who, ?int $staffId = null): array
    {
        return ['type' => $type, 'day_of_week' => $day, 'who' => $who, 'staff_id' => $staffId, 'slots' => [$this->brief($a), $this->brief($b)], 'reason' => null];
    }

    /** @param array<string, mixed> $s @return array<string, mixed> */
    private function brief(array $s): array
    {
        return ['id' => $s['id'], 'class_id' => $s['class_id'], 'class' => $s['class'], 'start_time' => $s['start_time'], 'end_time' => $s['end_time'], 'subject' => $s['subject'], 'room' => $s['room']];
    }
}
