<?php

declare(strict_types=1);

namespace App\Services\Academic\Gradebook;

use App\Models\Book;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who may see and enter which class's scores (docs/academics/gradebook.md).
 *
 * - `gradebook.manage-all`: every class and subject.
 * - `gradebook.approve`: views every class (to decide results), enters nothing.
 * - `gradebook.view`: classes the account teaches (the class's teacher list,
 *   or a subject assignment) or is homeroom teacher of.
 * - `gradebook.enter`: scores of a subject the account teaches in that class
 *   (on the class's teacher list, or assigned that subject or all subjects).
 */
final class GradebookAccess
{
    public function manageAll(User $user): bool
    {
        return $user->hasPermission(Permissions::GRADEBOOK_MANAGE_ALL);
    }

    /** May see every class's scores: enters all, or approves them. */
    public function viewAll(User $user): bool
    {
        return $user->hasAnyPermission(Permissions::GRADEBOOK_MANAGE_ALL, Permissions::GRADEBOOK_APPROVE);
    }

    public function canView(User $user, SchoolClass $class): bool
    {
        if ($this->viewAll($user)) {
            return true;
        }
        if (! $user->hasAnyPermission(Permissions::GRADEBOOK_VIEW, Permissions::GRADEBOOK_ENTER)) {
            return false;
        }
        $staffId = $this->staffId($user);

        return $staffId !== null && (
            (int) $class->homeroom_staff_id === $staffId
            || $this->onTeacherList($class->getKey(), $staffId)
            || DB::connection('tenant')->table('teacher_assignments')->where('class_id', $class->getKey())->where('staff_id', $staffId)->exists()
        );
    }

    public function canEnter(User $user, SchoolClass $class, int $bookId): bool
    {
        if ($this->manageAll($user)) {
            return true;
        }
        if (! $user->hasPermission(Permissions::GRADEBOOK_ENTER)) {
            return false;
        }
        $staffId = $this->staffId($user);

        return $staffId !== null && (
            $this->onTeacherList($class->getKey(), $staffId)
            || DB::connection('tenant')->table('teacher_assignments')->where('class_id', $class->getKey())->where('staff_id', $staffId)
                ->where(fn ($q) => $q->whereNull('book_id')->orWhere('book_id', $bookId))->exists()
        );
    }

    /**
     * The classes this account may open in the gradebook, newest year first.
     *
     * @return Collection<int, SchoolClass>
     */
    public function classes(User $user, ?int $academicYearId = null): Collection
    {
        $query = SchoolClass::query()->with(['gradeLevel:id,code,name,level_order', 'academicYear:id,name'])
            ->when($academicYearId, fn ($q, $id) => $q->where(fn ($q) => $q->where('academic_year_id', $id)->orWhereNull('academic_year_id')))
            ->orderBy('name');

        if (! $this->viewAll($user)) {
            $staffId = $this->staffId($user);
            if ($staffId === null || ! $user->hasAnyPermission(Permissions::GRADEBOOK_VIEW, Permissions::GRADEBOOK_ENTER)) {
                return collect();
            }
            $query->where(fn ($q) => $q->where('homeroom_staff_id', $staffId)
                ->orWhereIn('id', DB::connection('tenant')->table('class_teachers')->where('staff_id', $staffId)->select('class_id'))
                ->orWhereIn('id', DB::connection('tenant')->table('teacher_assignments')->where('staff_id', $staffId)->select('class_id')));
        }

        return $query->get();
    }

    /**
     * A class's subjects: its grade's curriculum, plus any subject a teacher
     * is assigned to in it.
     *
     * @return Collection<int, Book>
     */
    public function subjects(SchoolClass $class): Collection
    {
        $ids = DB::connection('tenant')->table('teacher_assignments')->where('class_id', $class->getKey())->whereNotNull('book_id')->pluck('book_id');
        if ($class->grade_level_id !== null) {
            $ids = $ids->merge(DB::connection('tenant')->table('book_grade_level')->where('grade_level_id', $class->grade_level_id)->pluck('book_id'));
        }

        return Book::query()->whereIn('id', $ids->unique()->all())->orderBy('title')->get(['id', 'title']);
    }

    /** Not cached: the controller holding this service lives across requests. */
    public function staffId(User $user): ?int
    {
        $id = Staff::query()->where('user_id', $user->getKey())->value('id');

        return $id === null ? null : (int) $id;
    }

    private function onTeacherList(int $classId, int $staffId): bool
    {
        return DB::connection('tenant')->table('class_teachers')->where('class_id', $classId)->where('staff_id', $staffId)->exists();
    }
}
