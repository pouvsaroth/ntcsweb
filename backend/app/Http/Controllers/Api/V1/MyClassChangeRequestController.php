<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\ClassChangeRequest;
use App\Models\Student;
use App\Services\Academic\ClassChangeRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * eApprovals > My Request > Request to change class — the signed-in
 * student's own requests. Identity-gated through `$user->student`, like
 * make-up class requests; no permission is needed to ask.
 */
final class MyClassChangeRequestController extends Controller
{
    public function __construct(private readonly ClassChangeRequestService $requests) {}

    /** The form: the student's active enrollments and the open classes. */
    public function options(Request $request): JsonResponse
    {
        return ApiResponse::success($this->requests->options($this->studentOrFail($request)));
    }

    public function index(Request $request): JsonResponse
    {
        $student = $this->studentOrFail($request);

        $rows = ClassChangeRequest::query()->where('student_id', $student->getKey())->latest('id')->limit(200)->get()
            ->map(fn (ClassChangeRequest $r) => $this->requests->present($r));

        return ApiResponse::success($rows);
    }

    public function store(Request $request): JsonResponse
    {
        $student = $this->studentOrFail($request);
        $data = $request->validate([
            'enrollment_id' => ['required', 'integer'],
            'to_class_id' => ['required', 'integer'],
            'start_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        return ApiResponse::created($this->requests->present($this->requests->submit($student, $data, $request->user())));
    }

    public function destroy(Request $request, ClassChangeRequest $classChangeRequest): JsonResponse
    {
        $this->requests->withdraw($classChangeRequest, $this->studentOrFail($request));

        return ApiResponse::noContent();
    }

    private function studentOrFail(Request $request): Student
    {
        $student = $request->user()?->student;
        if ($student === null) {
            throw ValidationException::withMessages(['student' => 'This account is not linked to a student record.']);
        }

        return $student;
    }
}
