<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AcademicTerm;
use App\Models\Book;
use App\Models\SchoolClass;
use App\Services\Academic\Gradebook\GradebookAccess;
use App\Services\Academic\Gradebook\GradebookService;
use App\Support\Authorization\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Academic > Examination: Grade Entry, Grade Validation and Score Calculation
 * (docs/academics/gradebook.md, 7b). Who sees which class and subject is
 * GradebookAccess; the work is GradebookService.
 */
final class GradebookController extends Controller
{
    public function __construct(
        private readonly GradebookAccess $access,
        private readonly GradebookService $gradebook,
    ) {}

    /** The classes this account may open, each with its subjects and whether they may enter scores. */
    public function classes(Request $request): JsonResponse
    {
        $this->ensureAnyGradebook($request);
        $yearId = $request->integer('academic_year_id') ?: null;
        $user = $request->user();

        return ApiResponse::success($this->access->classes($user, $yearId)->map(fn (SchoolClass $c) => [
            'id' => $c->id,
            'name' => $c->name,
            'grade_level' => $c->gradeLevel?->only(['id', 'code', 'name']),
            'academic_year' => $c->academicYear?->only(['id', 'name']),
            'subjects' => $this->access->subjects($c)->map(fn (Book $b) => [
                'id' => $b->id, 'title' => $b->title, 'can_enter' => $this->access->canEnter($user, $c, $b->id),
            ])->values(),
        ])->values());
    }

    public function sheet(Request $request): JsonResponse
    {
        [$class, $book, $term] = $this->target($request);
        abort_unless($this->access->canView($request->user(), $class), 403);

        return ApiResponse::success($this->gradebook->sheet($request->user(), $class, $book, $term));
    }

    public function save(Request $request): JsonResponse
    {
        [$class, $book, $term] = $this->target($request);
        abort_unless($this->access->canEnter($request->user(), $class, $book->getKey()), 403);

        $request->validate([
            'cells' => ['required', 'array', 'min:1', 'max:5000'],
            'cells.*.student_id' => ['required', 'integer'],
            'cells.*.component_id' => ['nullable', 'integer'],
            'cells.*.occurrence' => ['nullable', 'integer', 'min:1', 'max:12'],
            'cells.*.criterion_id' => ['nullable', 'integer'],
            'cells.*.score' => ['nullable'],
            'cells.*.absent' => ['nullable', 'boolean'],
            'cells.*.level_id' => ['nullable', 'integer'],
            'cells.*.remark' => ['nullable', 'string', 'max:255'],
        ]);

        $this->gradebook->save($request->user(), $class, $book, $term, $request->input('cells'));

        return ApiResponse::success($this->gradebook->sheet($request->user(), $class, $book, $term));
    }

    /** Grade Validation for a term: one class, or every class this account may open. */
    public function validation(Request $request): JsonResponse
    {
        $this->ensureAnyGradebook($request);
        $data = $request->validate([
            'academic_term_id' => ['required', 'integer', Rule::exists('tenant.academic_terms', 'id')],
            'class_id' => ['nullable', 'integer'],
        ]);
        $term = AcademicTerm::query()->findOrFail($data['academic_term_id']);
        $classes = $this->access->classes($request->user(), (int) $term->academic_year_id)
            ->when($data['class_id'] ?? null, fn ($c, $id) => $c->where('id', (int) $id));

        return ApiResponse::success($this->gradebook->validation($classes->values(), $term));
    }

    /** Score Calculation: one class's results for a term. */
    public function results(Request $request): JsonResponse
    {
        $data = $request->validate([
            'academic_term_id' => ['required', 'integer', Rule::exists('tenant.academic_terms', 'id')],
            'class_id' => ['required', 'integer', Rule::exists('tenant.classes', 'id')->whereNull('deleted_at')],
        ]);
        $class = SchoolClass::query()->findOrFail($data['class_id']);
        abort_unless($this->access->canView($request->user(), $class), 403);

        return ApiResponse::success($this->gradebook->overview($class, AcademicTerm::query()->findOrFail($data['academic_term_id'])));
    }

    /** @return array{0: SchoolClass, 1: Book, 2: AcademicTerm} */
    private function target(Request $request): array
    {
        $data = $request->validate([
            'class_id' => ['required', 'integer', Rule::exists('tenant.classes', 'id')->whereNull('deleted_at')],
            'book_id' => ['required', 'integer', Rule::exists('tenant.books', 'id')->whereNull('deleted_at')],
            'academic_term_id' => ['required', 'integer', Rule::exists('tenant.academic_terms', 'id')],
        ]);
        $class = SchoolClass::query()->findOrFail($data['class_id']);
        $term = AcademicTerm::query()->findOrFail($data['academic_term_id']);
        $this->gradebook->ensureTermFitsClass($class, $term);

        return [$class, Book::query()->findOrFail($data['book_id']), $term];
    }

    private function ensureAnyGradebook(Request $request): void
    {
        abort_unless($request->user()->hasAnyPermission(Permissions::GRADEBOOK_VIEW, Permissions::GRADEBOOK_ENTER, Permissions::GRADEBOOK_MANAGE_ALL, Permissions::GRADEBOOK_APPROVE), 403);
    }
}
