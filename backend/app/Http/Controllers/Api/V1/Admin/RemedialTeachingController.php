<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AcademicTerm;
use App\Models\RemedialSession;
use App\Models\RemedialSessionStudent;
use App\Models\SchoolClass;
use App\Services\Academic\Gradebook\GradebookAccess;
use App\Services\Academic\Gradebook\RemedialTeaching;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Academic > Examination > Remedial Teaching (docs/academics/gradebook.md,
 * 7e). Seeing a class needs gradebook access to it; recording a session needs
 * the right to enter scores for its subject.
 */
final class RemedialTeachingController extends Controller
{
    public function __construct(
        private readonly GradebookAccess $access,
        private readonly RemedialTeaching $remedial,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'class_id' => ['required', 'integer', Rule::exists('tenant.classes', 'id')->whereNull('deleted_at')],
            'academic_term_id' => ['required', 'integer', Rule::exists('tenant.academic_terms', 'id')],
            'book_id' => ['nullable', 'integer'],
        ]);
        $class = SchoolClass::query()->findOrFail($data['class_id']);
        abort_unless($this->access->canView($request->user(), $class), 403);

        return ApiResponse::success($this->remedial->overview($request->user(), $class, AcademicTerm::query()->findOrFail($data['academic_term_id']), $data['book_id'] ?? null));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $class = SchoolClass::query()->findOrFail($data['class_id']);
        $session = $this->remedial->save($request->user(), $class, AcademicTerm::query()->findOrFail($data['academic_term_id']), $data);

        return ApiResponse::success($this->remedial->present($request->user(), $session, $class), status: 201);
    }

    public function update(Request $request, RemedialSession $remedialSession): JsonResponse
    {
        $old = $remedialSession->schoolClass()->firstOrFail();
        abort_unless($this->access->canEnter($request->user(), $old, (int) $remedialSession->book_id), 403);

        $data = $this->validated($request);
        $class = SchoolClass::query()->findOrFail($data['class_id']);
        $session = $this->remedial->save($request->user(), $class, AcademicTerm::query()->findOrFail($data['academic_term_id']), $data, $remedialSession);

        return ApiResponse::success($this->remedial->present($request->user(), $session, $class));
    }

    public function destroy(Request $request, RemedialSession $remedialSession): JsonResponse
    {
        $this->remedial->delete($request->user(), $remedialSession);

        return ApiResponse::noContent();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'class_id' => ['required', 'integer', Rule::exists('tenant.classes', 'id')->whereNull('deleted_at')],
            'academic_term_id' => ['required', 'integer', Rule::exists('tenant.academic_terms', 'id')],
            'book_id' => ['required', 'integer', Rule::exists('tenant.books', 'id')->whereNull('deleted_at')],
            'staff_id' => ['sometimes', 'nullable', 'integer'],
            'session_date' => ['required', 'date'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i', 'after:start_time'],
            'topic' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'students' => ['required', 'array', 'min:1', 'max:500'],
            'students.*.student_id' => ['required', 'integer', 'distinct'],
            'students.*.attended' => ['nullable', 'boolean'],
            'students.*.outcome' => ['nullable', Rule::in(RemedialSessionStudent::OUTCOMES)],
            'students.*.remark' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
