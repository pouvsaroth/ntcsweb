<?php

declare(strict_types=1);

namespace App\Services\Academic;

use App\Models\ClassMakeupSession;
use App\Models\Enrollment;
use App\Models\ParentStudent;
use App\Models\Student;
use App\Models\TeacherSubstitution;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Notifications\NotificationService;
use App\Support\Notifications\NotificationType;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Teachers > Substitute with no substitute = the class is not held then
 * (docs/hrm/teachers.md). This tells the class's students and their parent
 * logins (parent portal):
 *
 * - class_not_held / class_back_on: the row is saved / removed again (back on
 *   only when they were told it was off);
 * - class_makeup_set / class_makeup_cancelled: a make-up session for it is
 *   set or changed / removed (cancelled only when they were told about it).
 *
 * Only for today or later.
 */
final class ClassNotHeldNotifier
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly TenantContext $context,
    ) {}

    /** @return int how many people were told */
    public function notHeld(TeacherSubstitution $row): int
    {
        return $row->substitute_staff_id === null ? $this->send($row->class_id, $row->date->toDateString(), NotificationType::CLASS_NOT_HELD, $this->rowData($row)) : 0;
    }

    /** The not-held row is being removed: the class is on again. */
    public function backOn(TeacherSubstitution $row): int
    {
        return $row->substitute_staff_id === null && $this->told(NotificationType::CLASS_NOT_HELD, 'substitution_id', $row->id)
            ? $this->send($row->class_id, $row->date->toDateString(), NotificationType::CLASS_BACK_ON, $this->rowData($row))
            : 0;
    }

    /** @return int how many people were told */
    public function makeupSet(ClassMakeupSession $makeup): int
    {
        return $this->send($makeup->class_id, $makeup->date->toDateString(), NotificationType::CLASS_MAKEUP_SET, $this->makeupData($makeup));
    }

    /** The make-up session is being removed. */
    public function makeupCancelled(ClassMakeupSession $makeup): int
    {
        return $this->told(NotificationType::CLASS_MAKEUP_SET, 'makeup_id', $makeup->id)
            ? $this->send($makeup->class_id, $makeup->date->toDateString(), NotificationType::CLASS_MAKEUP_CANCELLED, $this->makeupData($makeup))
            : 0;
    }

    private function told(string $type, string $key, int $id): bool
    {
        return UserNotification::query()->where('type', $type)->where("data->{$key}", $id)->exists();
    }

    /** @return array<string, mixed> */
    private function rowData(TeacherSubstitution $row): array
    {
        return [
            'substitution_id' => $row->id,
            'class_id' => $row->class_id,
            'class_name' => $row->schoolClass?->name ?? '',
            'date' => $row->date->format('d-m-Y'),
            'time' => $row->start_time !== null ? substr((string) $row->start_time, 0, 5).'–'.substr((string) $row->end_time, 0, 5) : '',
        ];
    }

    /** @return array<string, mixed> */
    private function makeupData(ClassMakeupSession $makeup): array
    {
        return [
            'makeup_id' => $makeup->id,
            'substitution_id' => $makeup->teacher_substitution_id,
            'class_id' => $makeup->class_id,
            'class_name' => $makeup->schoolClass?->name ?? '',
            'date' => $makeup->date->format('d-m-Y'),
            'time' => substr((string) $makeup->start_time, 0, 5).'–'.substr((string) $makeup->end_time, 0, 5),
        ];
    }

    /** @param  array<string, mixed>  $data */
    private function send(int $classId, string $date, string $type, array $data): int
    {
        if ($date < Carbon::today()->toDateString()) {
            return 0;
        }

        $studentIds = Enrollment::query()->active()->where('class_id', $classId)->pluck('student_id')->unique()->values();
        if ($studentIds->isEmpty()) {
            return 0;
        }

        $students = $this->activeUsers(Student::query()->whereIn('id', $studentIds)->whereNotNull('user_id')->pluck('user_id'));
        $parents = $this->activeUsers(ParentStudent::query()->whereIn('student_id', $studentIds)->pluck('user_id'))
            ->reject(fn (User $u) => $students->contains(fn (User $s) => $s->is($u)));

        $this->notifications->notifyMany($students, $type, $data, link: '/admin/my-attendance');
        $this->notifications->notifyMany($parents, $type, $data, link: '/admin/my-children');

        return $students->count() + $parents->count();
    }

    /** @return Collection<int, User> */
    private function activeUsers(Collection $ids): Collection
    {
        if ($ids->isEmpty()) {
            return collect();
        }

        return User::query()->inTenant($this->context->idOrFail())->active()->whereIn('id', $ids->unique()->values())->get()->toBase();
    }
}
