<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Book;
use App\Models\CurriculumLesson;
use App\Models\GradeLevel;
use App\Support\Authorization\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Academics > School setup > Curriculum (docs/academics/school-setup.md):
 * per grade level, its subjects (the school's Books) with hours per week,
 * and each subject's lessons with their hours.
 */
final class CurriculumController extends Controller
{
    /** One grade level's curriculum, plus the subjects that can still be added. */
    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission(Permissions::CURRICULUM_VIEW), 403);
        $request->validate(['grade_level_id' => ['required', 'integer', Rule::exists('tenant.grade_levels', 'id')]]);

        /** @var GradeLevel $grade */
        $grade = GradeLevel::query()->findOrFail($request->integer('grade_level_id'));
        $subjects = $grade->subjects()->orderBy('title')->get();
        $lessons = CurriculumLesson::query()->where('grade_level_id', $grade->getKey())
            ->orderBy('sort_order')->orderBy('id')->get()->groupBy('book_id');

        $rows = $subjects->map(function (Book $book) use ($lessons) {
            $own = $lessons->get($book->getKey(), collect());

            return [
                'book_id' => $book->id,
                'title' => $book->title,
                'status' => $book->status,
                'hours_per_week' => $book->pivot->hours_per_week !== null ? (float) $book->pivot->hours_per_week : null,
                'lessons_hours' => round((float) $own->sum(fn ($l) => (float) $l->hours), 2),
                'lessons' => $own->map(fn (CurriculumLesson $l) => $this->lesson($l))->values(),
            ];
        })->values();

        $taken = $subjects->pluck('id')->all();
        $available = Book::query()->active()->whereNotIn('id', $taken)->orderBy('title')->get(['id', 'title']);

        return ApiResponse::success([
            'grade_level' => ['id' => $grade->id, 'code' => $grade->code, 'name' => $grade->name],
            'subjects' => $rows,
            'hours_per_week' => round((float) $rows->sum(fn ($r) => $r['hours_per_week'] ?? 0), 2),
            'available_subjects' => $available->map(fn (Book $b) => ['id' => $b->id, 'title' => $b->title])->values(),
        ]);
    }

    /** Add a subject to a grade level, or change its hours per week. */
    public function saveSubject(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission(Permissions::CURRICULUM_MANAGE), 403);
        $data = $request->validate([
            'grade_level_id' => ['required', 'integer', Rule::exists('tenant.grade_levels', 'id')],
            'book_id' => ['required', 'integer', Rule::exists('tenant.books', 'id')->whereNull('deleted_at')],
            'hours_per_week' => ['nullable', 'numeric', 'min:0', 'max:99'],
        ]);

        /** @var GradeLevel $grade */
        $grade = GradeLevel::query()->findOrFail($data['grade_level_id']);
        $grade->subjects()->syncWithoutDetaching([$data['book_id'] => ['hours_per_week' => $data['hours_per_week'] ?? null]]);

        return ApiResponse::success(['saved' => true]);
    }

    /** Take a subject off a grade level, with its lessons there. */
    public function removeSubject(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission(Permissions::CURRICULUM_MANAGE), 403);
        $data = $request->validate([
            'grade_level_id' => ['required', 'integer', Rule::exists('tenant.grade_levels', 'id')],
            'book_id' => ['required', 'integer'],
        ]);

        DB::connection('tenant')->transaction(function () use ($data) {
            CurriculumLesson::query()->where('grade_level_id', $data['grade_level_id'])->where('book_id', $data['book_id'])->delete();
            GradeLevel::query()->findOrFail($data['grade_level_id'])->subjects()->detach($data['book_id']);
        });

        return ApiResponse::noContent();
    }

    public function storeLesson(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission(Permissions::CURRICULUM_MANAGE), 403);
        $data = $this->validatedLesson($request);

        $data['sort_order'] ??= (int) CurriculumLesson::query()
            ->where('grade_level_id', $data['grade_level_id'])->where('book_id', $data['book_id'])->max('sort_order') + 1;

        return ApiResponse::created($this->lesson(CurriculumLesson::query()->create($data)));
    }

    public function updateLesson(Request $request, CurriculumLesson $lesson): JsonResponse
    {
        abort_unless($request->user()->hasPermission(Permissions::CURRICULUM_MANAGE), 403);
        $data = $this->validatedLesson($request, $lesson);
        $data['sort_order'] ??= $lesson->sort_order;

        $lesson->update($data);

        return ApiResponse::success($this->lesson($lesson));
    }

    public function destroyLesson(Request $request, CurriculumLesson $lesson): JsonResponse
    {
        abort_unless($request->user()->hasPermission(Permissions::CURRICULUM_MANAGE), 403);

        $lesson->delete();

        return ApiResponse::noContent();
    }

    /** @return array<string, mixed> */
    private function validatedLesson(Request $request, ?CurriculumLesson $lesson = null): array
    {
        $data = $request->validate([
            'grade_level_id' => [$lesson ? 'sometimes' : 'required', 'integer', Rule::exists('tenant.grade_levels', 'id')],
            'book_id' => [$lesson ? 'sometimes' : 'required', 'integer', Rule::exists('tenant.books', 'id')],
            'title' => ['required', 'string', 'max:255'],
            'hours' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'description' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        // A lesson belongs to a subject the grade level teaches.
        $gradeId = $data['grade_level_id'] ?? $lesson?->grade_level_id;
        $bookId = $data['book_id'] ?? $lesson?->book_id;
        $taught = DB::connection('tenant')->table('book_grade_level')->where('grade_level_id', $gradeId)->where('book_id', $bookId)->exists();
        if (! $taught) {
            throw ValidationException::withMessages(['book_id' => __('Add this subject to the grade level first.')]);
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function lesson(CurriculumLesson $l): array
    {
        return [
            'id' => $l->id,
            'grade_level_id' => $l->grade_level_id,
            'book_id' => $l->book_id,
            'title' => $l->title,
            'hours' => $l->hours !== null ? (float) $l->hours : null,
            'description' => $l->description,
            'sort_order' => $l->sort_order,
        ];
    }
}
