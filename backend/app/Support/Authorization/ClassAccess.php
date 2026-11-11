<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Models\Role;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Who sees which classes (Roles > Data view).
 *
 * A user sees every class unless all their roles are set to "Assigned
 * classes only". Then they see the classes they teach (teacher or assistant
 * on the class, homeroom teacher, or a subject teacher), the classes they
 * cover as a substitute today, plus the extra classes picked for them on
 * Users > Edit.
 */
final class ClassAccess
{
    /** @var array<int, list<int>|null> per user id, for this request */
    private array $memo = [];

    /** @return list<int>|null class ids, or null when every class is visible */
    public function visibleClassIds(User $user): ?array
    {
        $id = (int) $user->getKey();
        if (! array_key_exists($id, $this->memo)) {
            $this->memo[$id] = $this->seesAll($user) ? null : $this->assignedClassIds($user);
        }

        return $this->memo[$id];
    }

    public function seesAll(User $user): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }
        $roles = $user->roles()->get(['roles.id', 'roles.slug', 'roles.data_scope']);

        return $roles->isEmpty()
            || $roles->contains(fn (Role $r) => $r->slug === Role::SCHOOL_ADMIN || $r->data_scope !== Role::SCOPE_ASSIGNED);
    }

    public function canSee(User $user, SchoolClass|int $class): bool
    {
        $ids = $this->visibleClassIds($user);

        return $ids === null || in_array($class instanceof SchoolClass ? (int) $class->getKey() : $class, $ids, true);
    }

    /**
     * Limit a classes query (or any query with a class id column) to what the user sees.
     *
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     */
    public function limit(Builder $query, User $user, string $column = 'id'): void
    {
        $ids = $this->visibleClassIds($user);
        if ($ids !== null) {
            $query->whereIn($query->qualifyColumn($column), $ids);
        }
    }

    /**
     * Students: only those with an enrollment in one of the user's classes.
     *
     * @param  Builder<Student>  $query
     */
    public function limitStudents(Builder $query, User $user): void
    {
        $ids = $this->visibleClassIds($user);
        if ($ids !== null) {
            $query->whereHas('enrollments', fn (Builder $e) => $e->whereIn('class_id', $ids));
        }
    }

    public function canSeeStudent(User $user, Student $student): bool
    {
        $ids = $this->visibleClassIds($user);

        return $ids === null || $student->enrollments()->whereIn('class_id', $ids)->exists();
    }

    /**
     * Taking attendance: the user's own classes, or a class they cover as a
     * substitute (Teacher > Substitute) on that date.
     */
    public function canTakeAttendance(User $user, SchoolClass $class, string $date): bool
    {
        if ($this->canSee($user, $class)) {
            return true;
        }
        $db = DB::connection('tenant');
        $staffIds = $db->table('staff')->where('user_id', $user->getKey())->pluck('id');

        return $staffIds->isNotEmpty() && $db->table('teacher_substitutions')
            ->where('class_id', $class->getKey())
            ->whereIn('substitute_staff_id', $staffIds)
            ->whereDate('date', $date)
            ->exists();
    }

    /** @return list<int> */
    public function assignedClassIds(User $user): array
    {
        $db = DB::connection('tenant');
        $staffIds = $db->table('staff')->where('user_id', $user->getKey())->pluck('id');

        $ids = $db->table('user_class_access')->where('user_id', $user->getKey())->pluck('class_id');
        if ($staffIds->isNotEmpty()) {
            $ids = $ids
                ->merge($db->table('class_teachers')->whereIn('staff_id', $staffIds)->pluck('class_id'))
                ->merge($db->table('classes')->whereIn('homeroom_staff_id', $staffIds)->pluck('id'))
                ->merge($db->table('teacher_assignments')->whereIn('staff_id', $staffIds)->pluck('class_id'))
                // Covering a class as a substitute today (Teacher > Substitute).
                ->merge($db->table('teacher_substitutions')->whereIn('substitute_staff_id', $staffIds)->whereDate('date', $this->schoolToday())->pluck('class_id'));
        }

        return $ids->map(fn ($v) => (int) $v)->unique()->sort()->values()->all();
    }

    private function schoolToday(): string
    {
        return Carbon::now(app(TenantContext::class)->get()?->timezone ?: 'Asia/Phnom_Penh')->toDateString();
    }

    /** Forget the remembered ids (after a role or extra classes change in the same request). */
    public function forget(?User $user = null): void
    {
        if ($user === null) {
            $this->memo = [];
        } else {
            unset($this->memo[(int) $user->getKey()]);
        }
    }
}
