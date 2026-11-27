<?php

declare(strict_types=1);

namespace App\Services\Academic;

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
 * logins (parent portal), and tells them again that it is on when that row is
 * removed — only for today or later, and only when they were told it was off.
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
        return $this->send($row, NotificationType::CLASS_NOT_HELD);
    }

    /** The not-held row is being removed: the class is on again. */
    public function backOn(TeacherSubstitution $row): int
    {
        $told = UserNotification::query()->where('type', NotificationType::CLASS_NOT_HELD)
            ->where('data->substitution_id', $row->id)->exists();

        return $told ? $this->send($row, NotificationType::CLASS_BACK_ON) : 0;
    }

    private function send(TeacherSubstitution $row, string $type): int
    {
        if ($row->substitute_staff_id !== null || $row->date->toDateString() < Carbon::today()->toDateString()) {
            return 0;
        }

        $studentIds = Enrollment::query()->active()->where('class_id', $row->class_id)->pluck('student_id')->unique()->values();
        if ($studentIds->isEmpty()) {
            return 0;
        }

        $data = [
            'substitution_id' => $row->id,
            'class_id' => $row->class_id,
            'class_name' => $row->schoolClass?->name ?? '',
            'date' => $row->date->format('d-m-Y'),
            'time' => $row->start_time !== null ? substr((string) $row->start_time, 0, 5).'–'.substr((string) $row->end_time, 0, 5) : '',
        ];

        $students = $this->activeUsers(
            Student::query()->whereIn('id', $studentIds)->whereNotNull('user_id')->pluck('user_id'),
        );
        $parents = $this->activeUsers(
            ParentStudent::query()->whereIn('student_id', $studentIds)->pluck('user_id'),
        )->reject(fn (User $u) => $students->contains(fn (User $s) => $s->is($u)));

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
