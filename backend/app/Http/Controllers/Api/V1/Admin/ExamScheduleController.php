<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\ExamSchedule;
use App\Models\SchoolClass;
use App\Support\Authorization\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Academic > Examination > Exam Schedule (docs/academics/gradebook.md): each
 * subject's exam for a whole grade or one class. Two exams at overlapping
 * times may not share students (same class, or a class inside the grade),
 * a room or an invigilator.
 */
final class ExamScheduleController extends Controller
{
    private const WITH = [
        'academicYear:id,name', 'term:id,name', 'component:id,name,name_km', 'gradeLevel:id,code,name',
        'schoolClass:id,name,grade_level_id', 'book:id,title', 'classroom:id,name', 'invigilator:id,first_name,last_name',
    ];

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission(Permissions::EXAM_SCHEDULES_VIEW), 403);

        $filters = $request->validate([
            'academic_year_id' => ['nullable', 'integer'],
            'academic_term_id' => ['nullable', 'integer'],
            'grade_level_id' => ['nullable', 'integer'],
            'class_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $query = ExamSchedule::query()->with(self::WITH)
            ->when($filters['academic_year_id'] ?? null, fn ($q, $v) => $q->where('academic_year_id', $v))
            ->when($filters['academic_term_id'] ?? null, fn ($q, $v) => $q->where('academic_term_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('exam_date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('exam_date', '<=', $v));

        // A grade filter shows its grade-wide exams and its classes' own; a class filter shows its own and its grade's.
        if ($gradeId = $filters['grade_level_id'] ?? null) {
            $query->where(fn ($q) => $q->where('grade_level_id', $gradeId)
                ->orWhereIn('class_id', SchoolClass::query()->where('grade_level_id', $gradeId)->select('id')));
        }
        if ($classId = $filters['class_id'] ?? null) {
            $gradeOfClass = SchoolClass::query()->whereKey($classId)->value('grade_level_id');
            $query->where(fn ($q) => $q->where('class_id', $classId)->when($gradeOfClass, fn ($q) => $q->orWhere('grade_level_id', $gradeOfClass)));
        }

        $rows = $query->orderBy('exam_date')->orderBy('start_time')->get();

        return ApiResponse::success($rows->map(fn (ExamSchedule $e) => $this->row($e))->values());
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission(Permissions::EXAM_SCHEDULES_MANAGE), 403);

        $data = $this->validated($request);
        $this->ensureNoClash($data);
        $exam = ExamSchedule::query()->create($data);

        return ApiResponse::created($this->row($exam->load(self::WITH)));
    }

    public function update(Request $request, ExamSchedule $examSchedule): JsonResponse
    {
        abort_unless($request->user()->hasPermission(Permissions::EXAM_SCHEDULES_MANAGE), 403);

        $data = $this->validated($request);
        $this->ensureNoClash($data, $examSchedule->getKey());
        $examSchedule->update($data);

        return ApiResponse::success($this->row($examSchedule->fresh(self::WITH)));
    }

    public function destroy(Request $request, ExamSchedule $examSchedule): JsonResponse
    {
        abort_unless($request->user()->hasPermission(Permissions::EXAM_SCHEDULES_MANAGE), 403);

        $examSchedule->delete();

        return ApiResponse::noContent();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'academic_year_id' => ['required', 'integer', Rule::exists('tenant.academic_years', 'id')],
            'academic_term_id' => ['nullable', 'integer', Rule::exists('tenant.academic_terms', 'id')->where('academic_year_id', $request->input('academic_year_id'))],
            'assessment_component_id' => ['nullable', 'integer', Rule::exists('tenant.assessment_components', 'id')],
            'grade_level_id' => ['nullable', 'required_without:class_id', 'integer', Rule::exists('tenant.grade_levels', 'id')],
            'class_id' => ['nullable', 'required_without:grade_level_id', 'integer', Rule::exists('tenant.classes', 'id')->whereNull('deleted_at')],
            'book_id' => ['required', 'integer', Rule::exists('tenant.books', 'id')->whereNull('deleted_at')],
            'exam_date' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'classroom_id' => ['nullable', 'integer', Rule::exists('tenant.classrooms', 'id')->whereNull('deleted_at')],
            'invigilator_staff_id' => ['nullable', 'integer', Rule::exists('tenant.staff', 'id')->whereNull('deleted_at')],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        // One class is enough: its grade is implied, never both.
        if (! empty($data['class_id'])) {
            $data['grade_level_id'] = null;
        }

        return $data;
    }

    /**
     * Refuse an exam that overlaps another on the same day and shares its
     * students, room or invigilator.
     *
     * @param  array<string, mixed>  $data
     */
    private function ensureNoClash(array $data, ?int $ignoreId = null): void
    {
        $others = ExamSchedule::query()->with(['schoolClass:id,name,grade_level_id', 'gradeLevel:id,name', 'book:id,title'])
            ->whereDate('exam_date', $data['exam_date'])
            ->where('start_time', '<', $data['end_time'])
            ->where('end_time', '>', $data['start_time'])
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->get();
        if ($others->isEmpty()) {
            return;
        }

        $classGrade = ! empty($data['class_id']) ? SchoolClass::query()->whereKey($data['class_id'])->value('grade_level_id') : null;
        $grade = $data['grade_level_id'] ?? null;

        $sameStudents = $others->first(function (ExamSchedule $o) use ($data, $grade, $classGrade) {
            $oGrade = $o->grade_level_id;
            $oClassGrade = $o->schoolClass?->grade_level_id;

            return (! empty($data['class_id']) && $o->class_id === (int) $data['class_id'])
                || ($grade !== null && ($oGrade === (int) $grade || $oClassGrade === (int) $grade))
                || ($classGrade !== null && $oGrade === $classGrade);
        });
        $sameRoom = ! empty($data['classroom_id']) ? $others->firstWhere('classroom_id', (int) $data['classroom_id']) : null;
        $sameInvigilator = ! empty($data['invigilator_staff_id']) ? $others->firstWhere('invigilator_staff_id', (int) $data['invigilator_staff_id']) : null;

        $messages = [];
        if ($sameStudents) {
            $messages['class_id'] = __('These students already have an exam then: :exam.', ['exam' => $this->describe($sameStudents)]);
        }
        if ($sameRoom) {
            $messages['classroom_id'] = __('This room is already used then: :exam.', ['exam' => $this->describe($sameRoom)]);
        }
        if ($sameInvigilator) {
            $messages['invigilator_staff_id'] = __('This invigilator already watches an exam then: :exam.', ['exam' => $this->describe($sameInvigilator)]);
        }
        if ($messages !== []) {
            throw ValidationException::withMessages($messages);
        }
    }

    private function describe(ExamSchedule $e): string
    {
        $who = $e->schoolClass?->name ?? $e->gradeLevel?->name ?? '';

        return trim(($e->book?->title ?? '').' · '.$who.' · '.substr((string) $e->start_time, 0, 5).'–'.substr((string) $e->end_time, 0, 5), ' ·');
    }

    /** @return array<string, mixed> */
    private function row(ExamSchedule $e): array
    {
        return [
            'id' => $e->id,
            'academic_year' => $e->academicYear?->only(['id', 'name']),
            'term' => $e->term?->only(['id', 'name']),
            'component' => $e->component?->only(['id', 'name', 'name_km']),
            'grade_level' => $e->gradeLevel?->only(['id', 'code', 'name']),
            'class' => $e->schoolClass?->only(['id', 'name']),
            'book' => $e->book?->only(['id', 'title']),
            'exam_date' => $e->exam_date?->toDateString(),
            'start_time' => substr((string) $e->start_time, 0, 5),
            'end_time' => substr((string) $e->end_time, 0, 5),
            'classroom' => $e->classroom?->only(['id', 'name']),
            'invigilator' => $e->invigilator ? ['id' => $e->invigilator->id, 'name' => $e->invigilator->fullName()] : null,
            'notes' => $e->notes,
        ];
    }
}
