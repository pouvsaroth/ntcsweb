<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\PromotionRule;
use App\Models\SchoolClass;
use App\Services\Academic\PromotionService;
use App\Support\Authorization\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Academics > School setup > Promotion rules and Promote students
 * (docs/academics/school-setup.md, stage 4).
 */
final class StudentPromotionController extends Controller
{
    public function __construct(private readonly PromotionService $promotions) {}

    /** The school's default rule (grade_level_id null) and each grade's own. */
    public function rules(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasAnyPermission(Permissions::PROMOTION_RULES_VIEW, Permissions::STUDENT_PROMOTIONS_VIEW), 403);

        $rules = PromotionRule::query()->with('gradeLevel:id,name,code,level_order')->get()
            ->sortBy(fn (PromotionRule $r) => $r->gradeLevel?->level_order ?? -1)
            ->map(fn (PromotionRule $r) => $this->rule($r))->values();

        return ApiResponse::success($rules);
    }

    /** Set the rule of one grade level (or the default rule when grade_level_id is empty). */
    public function saveRule(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission(Permissions::PROMOTION_RULES_MANAGE), 403);
        $data = $request->validate([
            'grade_level_id' => ['nullable', 'integer', Rule::exists('tenant.grade_levels', 'id')],
            'min_average' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'min_attendance' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'max_failed_subjects' => ['nullable', 'integer', 'min:0', 'max:100'],
            'pass_mark' => ['required', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $rule = PromotionRule::query()->firstOrNew(['grade_level_id' => $data['grade_level_id'] ?? null]);
        $rule->fill($data)->save();

        return ApiResponse::success($this->rule($rule->load('gradeLevel:id,name,code,level_order')));
    }

    public function destroyRule(Request $request, PromotionRule $promotionRule): JsonResponse
    {
        abort_unless($request->user()->hasPermission(Permissions::PROMOTION_RULES_MANAGE), 403);

        $promotionRule->delete();

        return ApiResponse::noContent();
    }

    /** The classes of a grade level, newest year first — the class picker of Promote students. */
    public function classes(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission(Permissions::STUDENT_PROMOTIONS_VIEW), 403);
        $request->validate(['grade_level_id' => ['required', 'integer', Rule::exists('tenant.grade_levels', 'id')]]);

        $classes = SchoolClass::query()->with('academicYear:id,name')
            ->where('grade_level_id', $request->integer('grade_level_id'))
            ->where('status', '!=', SchoolClass::STATUS_CANCELLED)
            ->withCount(['enrollments as students_count' => fn ($q) => $q->whereIn('status', ['not_started', 'active', 'exam_ready', 'completed'])])
            ->orderByDesc('academic_year_id')->orderBy('name')
            ->get()
            ->map(fn (SchoolClass $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'status' => $c->status,
                'academic_year' => $c->academicYear?->name,
                'students_count' => (int) $c->students_count,
            ])->values();

        return ApiResponse::success($classes);
    }

    /** A class's students checked against its rule, and the classes they can go into. */
    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission(Permissions::STUDENT_PROMOTIONS_VIEW), 403);
        $request->validate(['class_id' => ['required', 'integer', Rule::exists('tenant.classes', 'id')->whereNull('deleted_at')]]);

        /** @var SchoolClass $class */
        $class = SchoolClass::query()->with(['gradeLevel', 'academicYear:id,name'])->findOrFail($request->integer('class_id'));
        $result = $this->promotions->evaluate($class);
        $next = $class->gradeLevel?->next();

        $classOption = fn (SchoolClass $c) => ['id' => $c->id, 'name' => $c->name, 'academic_year' => $c->academicYear?->name, 'grade_level' => $c->gradeLevel?->name];
        $classesOf = fn (?int $gradeId) => $gradeId === null ? collect() : SchoolClass::query()->with(['academicYear:id,name', 'gradeLevel:id,name'])
            ->where('grade_level_id', $gradeId)->whereKeyNot($class->getKey())
            ->whereIn('status', [SchoolClass::STATUS_UPCOMING, SchoolClass::STATUS_ACTIVE])
            ->orderBy('name')->get()->map($classOption)->values();

        return ApiResponse::success([
            'class' => [
                'id' => $class->id,
                'name' => $class->name,
                'academic_year' => $class->academicYear?->name,
                'grade_level' => $class->gradeLevel ? ['id' => $class->gradeLevel->id, 'name' => $class->gradeLevel->name] : null,
            ],
            'next_grade_level' => $next ? ['id' => $next->id, 'name' => $next->name] : null,
            'rule' => $result['rule'] ? $this->rule($result['rule']->load('gradeLevel:id,name,code,level_order')) : null,
            // gradebook: approved term results (7e); exams: exam applications.
            'source' => $result['source'],
            'unapproved_sheets' => $result['unapproved_sheets'],
            'students' => $result['students'],
            'promote_classes' => $classesOf($next?->id),
            'repeat_classes' => $classesOf($class->grade_level_id),
        ]);
    }

    public function apply(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission(Permissions::STUDENT_PROMOTIONS_RUN), 403);
        $classExists = Rule::exists('tenant.classes', 'id')->whereNull('deleted_at');
        $data = $request->validate([
            'class_id' => ['required', 'integer', $classExists],
            'to_class_id' => ['nullable', 'integer', $classExists],
            'repeat_class_id' => ['nullable', 'integer', $classExists],
            'students' => ['required', 'array', 'min:1'],
            'students.*.enrollment_id' => ['required', 'integer'],
            'students.*.action' => ['required', Rule::in(['promote', 'repeat', 'graduate'])],
        ]);

        $find = fn (?int $id) => $id ? SchoolClass::query()->findOrFail($id) : null;
        $counts = $this->promotions->apply(
            SchoolClass::query()->findOrFail($data['class_id']),
            $find($data['to_class_id'] ?? null),
            $find($data['repeat_class_id'] ?? null),
            $data['students'],
            $request->user(),
        );

        return ApiResponse::success($counts);
    }

    /** @return array<string, mixed> */
    private function rule(PromotionRule $r): array
    {
        return [
            'id' => $r->id,
            'grade_level_id' => $r->grade_level_id,
            'grade_level' => $r->gradeLevel?->name,
            'min_average' => $r->min_average !== null ? (float) $r->min_average : null,
            'min_attendance' => $r->min_attendance !== null ? (float) $r->min_attendance : null,
            'max_failed_subjects' => $r->max_failed_subjects,
            'pass_mark' => (float) $r->pass_mark,
            'notes' => $r->notes,
        ];
    }
}
