<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\GradeLevel;
use App\Support\Authorization\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Academics > School setup > Grade levels (docs/academics/school-setup.md):
 * Grade 1–12 or any level the school adds, in order, by education stage.
 */
final class GradeLevelController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Also the grade picker of the class form, the curriculum, rules and promotions.
        abort_unless($request->user()->hasAnyPermission(
            Permissions::GRADE_LEVELS_VIEW, Permissions::CLASSES_VIEW, Permissions::CURRICULUM_VIEW,
            Permissions::PROMOTION_RULES_VIEW, Permissions::STUDENT_PROMOTIONS_VIEW, Permissions::BOOKS_VIEW,
        ), 403);

        $levels = GradeLevel::query()->withCount('classes')->ordered()
            ->when($request->boolean('active_only'), fn ($q) => $q->where('is_active', true))
            ->get();

        return ApiResponse::success($levels->map(fn (GradeLevel $g) => $this->row($g))->values());
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission(Permissions::GRADE_LEVELS_MANAGE), 403);

        $level = GradeLevel::query()->create($this->validated($request));

        return ApiResponse::created($this->row($level->loadCount('classes')));
    }

    public function update(Request $request, GradeLevel $gradeLevel): JsonResponse
    {
        abort_unless($request->user()->hasPermission(Permissions::GRADE_LEVELS_MANAGE), 403);

        $gradeLevel->update($this->validated($request, $gradeLevel));

        return ApiResponse::success($this->row($gradeLevel->loadCount('classes')));
    }

    public function destroy(Request $request, GradeLevel $gradeLevel): JsonResponse
    {
        abort_unless($request->user()->hasPermission(Permissions::GRADE_LEVELS_MANAGE), 403);

        if ($gradeLevel->classes()->exists()) {
            throw ValidationException::withMessages(['grade_level' => __('Classes use this grade level. Make it inactive instead, or move the classes first.')]);
        }

        $gradeLevel->delete();

        return ApiResponse::noContent();
    }

    /**
     * Grade 1–12 in one click (a code that already exists is left as it is):
     * 1–6 primary, 7–9 lower secondary, 10–12 upper secondary.
     */
    public function seedDefaults(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission(Permissions::GRADE_LEVELS_MANAGE), 403);

        $added = DB::connection('tenant')->transaction(function () {
            $added = 0;
            for ($n = 1; $n <= 12; $n++) {
                $level = GradeLevel::query()->firstOrCreate(['code' => "G{$n}"], [
                    'name' => "Grade {$n}",
                    'stage' => $n <= 6 ? 'primary' : ($n <= 9 ? 'lower_secondary' : 'upper_secondary'),
                    'level_order' => $n,
                    'is_active' => true,
                ]);
                $added += $level->wasRecentlyCreated ? 1 : 0;
            }

            return $added;
        });

        return ApiResponse::success(['added' => $added]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?GradeLevel $level = null): array
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);

        return $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique('tenant.grade_levels', 'code')->ignore($level?->getKey())],
            'name' => ['required', 'string', 'max:100'],
            'stage' => ['nullable', Rule::in(GradeLevel::STAGES)],
            'level_order' => ['required', 'integer', 'min:0', 'max:999'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    /** @return array<string, mixed> */
    private function row(GradeLevel $g): array
    {
        return [
            'id' => $g->id,
            'code' => $g->code,
            'name' => $g->name,
            'stage' => $g->stage,
            'level_order' => $g->level_order,
            'is_active' => $g->is_active,
            'classes_count' => (int) ($g->classes_count ?? 0),
        ];
    }
}
