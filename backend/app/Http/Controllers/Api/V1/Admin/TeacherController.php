<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AcademicYear;
use App\Models\Book;
use App\Models\Position;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\TeacherAssignment;
use App\Models\TeacherQualification;
use App\Support\Authorization\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;

/**
 * HRM > Teacher (docs/hrm/teachers.md): the teacher list and pickers,
 * qualifications and specialised subjects, assignments, homeroom and the
 * teaching load. A teacher is a Staff member whose position carries the
 * Teacher role; their profile and employment are on Staff.
 */
final class TeacherController extends Controller
{
    /** Staff in a teacher position, with what the pickers need. */
    public function index(Request $request): JsonResponse
    {
        $this->can($request, Permissions::TEACHERS_VIEW);

        return ApiResponse::success($this->teachers()->map(fn (Staff $s) => $this->teacher($s))->values());
    }

    /** Classes, subjects and academic years for the forms. */
    public function options(Request $request): JsonResponse
    {
        $this->can($request, Permissions::TEACHERS_VIEW);

        return ApiResponse::success([
            'classes' => SchoolClass::query()->with(['academicYear:id,name', 'gradeLevel:id,name'])
                ->whereIn('status', [SchoolClass::STATUS_UPCOMING, SchoolClass::STATUS_ACTIVE])
                ->orderBy('name')->get()
                ->map(fn (SchoolClass $c) => [
                    'id' => $c->id, 'name' => $c->name, 'academic_year_id' => $c->academic_year_id,
                    'academic_year' => $c->academicYear?->name, 'grade_level' => $c->gradeLevel?->name,
                ])->values(),
            'subjects' => Book::query()->active()->orderBy('title')->get(['id', 'title'])->map(fn (Book $b) => ['id' => $b->id, 'title' => $b->title])->values(),
            'academic_years' => AcademicYear::query()->orderByDesc('name')->get(['id', 'name', 'is_current'])
                ->map(fn (AcademicYear $y) => ['id' => $y->id, 'name' => $y->name, 'is_current' => $y->is_current])->values(),
        ]);
    }

    // --- Qualification ---------------------------------------------------------

    public function qualifications(Request $request): JsonResponse
    {
        $this->can($request, Permissions::TEACHERS_VIEW);
        $request->validate(['staff_id' => ['required', 'integer', $this->teacherRule()]]);
        $staffId = $request->integer('staff_id');

        $rows = TeacherQualification::query()->where('staff_id', $staffId)
            ->orderByRaw("case type when 'degree' then 0 when 'certificate' then 1 else 2 end")
            ->orderByDesc('year')->orderBy('id')->get();
        $subjects = DB::connection('tenant')->table('teacher_subjects as ts')->join('books as b', 'b.id', '=', 'ts.book_id')
            ->where('ts.staff_id', $staffId)->orderBy('b.title')->get(['b.id', 'b.title']);

        return ApiResponse::success([
            'qualifications' => $rows->map(fn (TeacherQualification $q) => $this->qualification($q))->values(),
            'subjects' => $subjects->map(fn ($b) => ['id' => (int) $b->id, 'title' => $b->title])->values(),
        ]);
    }

    public function storeQualification(Request $request): JsonResponse
    {
        $this->can($request, Permissions::TEACHERS_MANAGE);

        return ApiResponse::created($this->qualification(TeacherQualification::query()->create($this->validatedQualification($request))));
    }

    public function updateQualification(Request $request, TeacherQualification $qualification): JsonResponse
    {
        $this->can($request, Permissions::TEACHERS_MANAGE);
        $qualification->update($this->validatedQualification($request));

        return ApiResponse::success($this->qualification($qualification));
    }

    public function destroyQualification(Request $request, TeacherQualification $qualification): JsonResponse
    {
        $this->can($request, Permissions::TEACHERS_MANAGE);
        $qualification->delete();

        return ApiResponse::noContent();
    }

    /** The subjects a teacher specialises in (replaces the list). */
    public function saveSubjects(Request $request): JsonResponse
    {
        $this->can($request, Permissions::TEACHERS_MANAGE);
        $data = $request->validate([
            'staff_id' => ['required', 'integer', $this->teacherRule()],
            'book_ids' => ['present', 'array'],
            'book_ids.*' => ['integer', Rule::exists('tenant.books', 'id')->whereNull('deleted_at')],
        ]);

        DB::connection('tenant')->transaction(function () use ($data) {
            $table = fn () => DB::connection('tenant')->table('teacher_subjects')->where('staff_id', $data['staff_id']);
            $table()->whereNotIn('book_id', $data['book_ids'])->delete();
            $have = $table()->pluck('book_id')->map(fn ($id) => (int) $id)->all();
            foreach (array_diff(array_unique(array_map('intval', $data['book_ids'])), $have) as $bookId) {
                DB::connection('tenant')->table('teacher_subjects')->insert(['staff_id' => $data['staff_id'], 'book_id' => $bookId, 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        return ApiResponse::success(['saved' => true]);
    }

    // --- Assignment -------------------------------------------------------------

    public function assignments(Request $request): JsonResponse
    {
        $this->can($request, Permissions::TEACHERS_VIEW);

        $rows = TeacherAssignment::query()->with(['staff', 'schoolClass.academicYear:id,name', 'book:id,title', 'academicYear:id,name'])
            ->when($request->filled('staff_id'), fn ($q) => $q->where('staff_id', $request->integer('staff_id')))
            ->when($request->filled('class_id'), fn ($q) => $q->where('class_id', $request->integer('class_id')))
            ->when($request->filled('academic_year_id'), fn ($q) => $q->where('academic_year_id', $request->integer('academic_year_id')))
            ->orderBy('class_id')->orderBy('id')->get();

        return ApiResponse::success($rows->map(fn (TeacherAssignment $a) => $this->assignment($a))->values());
    }

    public function storeAssignment(Request $request): JsonResponse
    {
        $this->can($request, Permissions::TEACHERS_MANAGE);
        $assignment = DB::connection('tenant')->transaction(function () use ($request) {
            $assignment = TeacherAssignment::query()->create($this->validatedAssignment($request));
            $this->teachClass($assignment);

            return $assignment;
        });

        return ApiResponse::created($this->assignment($assignment->load(['staff', 'schoolClass.academicYear:id,name', 'book:id,title', 'academicYear:id,name'])));
    }

    public function updateAssignment(Request $request, TeacherAssignment $assignment): JsonResponse
    {
        $this->can($request, Permissions::TEACHERS_MANAGE);
        DB::connection('tenant')->transaction(function () use ($request, $assignment) {
            $assignment->update($this->validatedAssignment($request, $assignment));
            $this->teachClass($assignment);
        });

        return ApiResponse::success($this->assignment($assignment->load(['staff', 'schoolClass.academicYear:id,name', 'book:id,title', 'academicYear:id,name'])));
    }

    public function destroyAssignment(Request $request, TeacherAssignment $assignment): JsonResponse
    {
        $this->can($request, Permissions::TEACHERS_MANAGE);
        // The class keeps the teacher (Classes form): they may teach it for another reason.
        $assignment->delete();

        return ApiResponse::noContent();
    }

    // --- Homeroom ---------------------------------------------------------------

    public function homerooms(Request $request): JsonResponse
    {
        $this->can($request, Permissions::TEACHERS_VIEW);

        $classes = SchoolClass::query()->with(['academicYear:id,name', 'gradeLevel:id,name,level_order'])
            ->whereIn('status', [SchoolClass::STATUS_UPCOMING, SchoolClass::STATUS_ACTIVE])
            ->when($request->filled('academic_year_id'), fn ($q) => $q->where('academic_year_id', $request->integer('academic_year_id')))
            ->orderBy('name')->get();
        $names = Staff::query()->whereIn('id', $classes->pluck('homeroom_staff_id')->filter()->unique())->get()->keyBy('id');

        return ApiResponse::success($classes->map(fn (SchoolClass $c) => [
            'class_id' => $c->id,
            'class' => $c->name,
            'academic_year' => $c->academicYear?->name,
            'grade_level' => $c->gradeLevel?->name,
            'homeroom_staff_id' => $c->homeroom_staff_id,
            'homeroom' => $c->homeroom_staff_id ? $names->get($c->homeroom_staff_id)?->fullName() : null,
        ])->values());
    }

    public function saveHomeroom(Request $request, SchoolClass $class): JsonResponse
    {
        $this->can($request, Permissions::TEACHERS_MANAGE);
        $data = $request->validate(['staff_id' => ['nullable', 'integer', $this->teacherRule()]]);

        $class->forceFill(['homeroom_staff_id' => $data['staff_id'] ?? null])->save();

        return ApiResponse::success(['class_id' => $class->id, 'homeroom_staff_id' => $class->homeroom_staff_id]);
    }

    // --- Teaching load -----------------------------------------------------------

    /**
     * Per teacher, a week: the hours of the classes they teach (class
     * schedules; upcoming and active classes), the hours set on their
     * assignments, the classes, subjects and the homeroom class.
     */
    public function load(Request $request): JsonResponse
    {
        $this->can($request, Permissions::TEACHERS_VIEW);
        $yearId = $request->filled('academic_year_id') ? $request->integer('academic_year_id') : null;

        $teachers = $this->teachers();
        $ids = $teachers->modelKeys();
        $db = DB::connection('tenant');

        $classRows = $db->table('class_teachers as ct')->join('classes as c', 'c.id', '=', 'ct.class_id')
            ->whereIn('ct.staff_id', $ids)->whereNull('c.deleted_at')
            ->whereIn('c.status', [SchoolClass::STATUS_UPCOMING, SchoolClass::STATUS_ACTIVE])
            ->when($yearId !== null, fn ($q) => $q->where('c.academic_year_id', $yearId))
            ->get(['ct.staff_id', 'c.id as class_id', 'c.name']);
        $minutes = $db->table('class_schedules')->whereIn('class_id', $classRows->pluck('class_id')->unique())
            ->get(['class_id', 'start_time', 'end_time'])
            ->groupBy('class_id')
            ->map(fn (Collection $slots) => $slots->sum(fn ($s) => max(0, (strtotime((string) $s->end_time) - strtotime((string) $s->start_time)) / 60)));

        $assigned = TeacherAssignment::query()->whereIn('staff_id', $ids)
            ->when($yearId !== null, fn ($q) => $q->where('academic_year_id', $yearId))
            ->get()->groupBy('staff_id');
        $homerooms = SchoolClass::query()->whereIn('homeroom_staff_id', $ids)
            ->whereIn('status', [SchoolClass::STATUS_UPCOMING, SchoolClass::STATUS_ACTIVE])
            ->when($yearId !== null, fn ($q) => $q->where('academic_year_id', $yearId))
            ->get(['id', 'name', 'homeroom_staff_id'])->groupBy('homeroom_staff_id');

        $rows = $teachers->map(function (Staff $s) use ($classRows, $minutes, $assigned, $homerooms) {
            $classes = $classRows->where('staff_id', $s->id);
            $own = $assigned->get($s->id, collect());

            return [
                'staff_id' => $s->id,
                'name' => $s->fullName(),
                'employee_code' => $s->employee_code,
                'classes' => $classes->pluck('name')->unique()->values(),
                'scheduled_hours' => round($classes->sum(fn ($c) => $minutes->get($c->class_id, 0)) / 60, 2),
                'assigned_hours' => round((float) $own->sum(fn ($a) => (float) $a->hours_per_week), 2),
                'subjects' => $own->pluck('book_id')->filter()->unique()->count(),
                'homeroom' => $homerooms->get($s->id, collect())->pluck('name')->values(),
            ];
        })->sortByDesc('scheduled_hours')->values();

        return ApiResponse::success($rows);
    }

    // --- Helpers -----------------------------------------------------------------

    /** @return Collection<int, Staff> */
    private function teachers(): Collection
    {
        return Staff::query()->with('position:id,name')
            ->whereIn('position_id', Position::teacherPositionIds())
            ->where('status', Staff::STATUS_ACTIVE)
            ->orderBy('first_name')->orderBy('last_name')->get();
    }

    private function teacherRule(): Exists
    {
        return Rule::exists('tenant.staff', 'id')->whereIn('position_id', Position::teacherPositionIds())->whereNull('deleted_at');
    }

    private function can(Request $request, string $permission): void
    {
        abort_unless($request->user()->hasPermission($permission), 403);
    }

    /** An assigned teacher also teaches the class (attendance and scores of their classes). */
    private function teachClass(TeacherAssignment $assignment): void
    {
        $exists = DB::connection('tenant')->table('class_teachers')
            ->where('class_id', $assignment->class_id)->where('staff_id', $assignment->staff_id)->exists();
        if (! $exists) {
            DB::connection('tenant')->table('class_teachers')->insert([
                'class_id' => $assignment->class_id, 'staff_id' => $assignment->staff_id,
                'role' => SchoolClass::ROLE_TEACHER, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function validatedQualification(Request $request): array
    {
        return $request->validate([
            'staff_id' => ['required', 'integer', $this->teacherRule()],
            'type' => ['required', Rule::in(TeacherQualification::TYPES)],
            'level' => ['nullable', Rule::in(TeacherQualification::LEVELS)],
            'title' => ['required', 'string', 'max:255'],
            'institution' => ['nullable', 'string', 'max:255'],
            'year' => ['nullable', 'integer', 'min:1950', 'max:2100'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    /** @return array<string, mixed> */
    private function validatedAssignment(Request $request, ?TeacherAssignment $assignment = null): array
    {
        $data = $request->validate([
            'staff_id' => ['required', 'integer', $this->teacherRule()],
            'class_id' => ['required', 'integer', Rule::exists('tenant.classes', 'id')->whereNull('deleted_at')],
            'book_id' => ['nullable', 'integer', Rule::exists('tenant.books', 'id')->whereNull('deleted_at')],
            'academic_year_id' => ['nullable', 'integer', Rule::exists('tenant.academic_years', 'id')->whereNull('deleted_at')],
            'hours_per_week' => ['nullable', 'numeric', 'min:0', 'max:99'],
        ]);
        // The class's year when none is given.
        $data['academic_year_id'] ??= SchoolClass::query()->whereKey($data['class_id'])->value('academic_year_id');

        $duplicate = TeacherAssignment::query()->where('staff_id', $data['staff_id'])->where('class_id', $data['class_id'])
            ->where(fn ($q) => isset($data['book_id']) ? $q->where('book_id', $data['book_id']) : $q->whereNull('book_id'))
            ->when($assignment !== null, fn ($q) => $q->whereKeyNot($assignment->getKey()))
            ->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['class_id' => __('This teacher already teaches this subject in this class.')]);
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function teacher(Staff $s): array
    {
        return ['id' => $s->id, 'name' => $s->fullName(), 'employee_code' => $s->employee_code, 'position' => $s->position?->name, 'phone' => $s->phone];
    }

    /** @return array<string, mixed> */
    private function qualification(TeacherQualification $q): array
    {
        return ['id' => $q->id, 'staff_id' => $q->staff_id, 'type' => $q->type, 'level' => $q->level, 'title' => $q->title, 'institution' => $q->institution, 'year' => $q->year, 'note' => $q->note];
    }

    /** @return array<string, mixed> */
    private function assignment(TeacherAssignment $a): array
    {
        return [
            'id' => $a->id,
            'staff_id' => $a->staff_id,
            'teacher' => $a->staff?->fullName(),
            'class_id' => $a->class_id,
            'class' => $a->schoolClass?->name,
            'book_id' => $a->book_id,
            'subject' => $a->book?->title,
            'academic_year_id' => $a->academic_year_id,
            'academic_year' => $a->academicYear?->name,
            'hours_per_week' => $a->hours_per_week !== null ? (float) $a->hours_per_week : null,
        ];
    }
}
