<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreStudentRequest;
use App\Http\Requests\Api\V1\Admin\UpdateStudentRequest;
use App\Http\Resources\StudentResource;
use App\Http\Responses\ApiResponse;
use App\Models\Student;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StudentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Student::class);

        // cursorPaginate, not paginate: this is the table the platform is
        // explicitly designed to hold millions of rows in, and offset
        // pagination's COUNT(*) plus "skip N rows" gets slower with every
        // page — cursor pagination stays constant cost regardless of depth.
        $students = ApiQuery::for(Student::query(), $request)
            ->searchable('name', 'student_code', 'email', 'guardian_name')
            ->filterable(['status'])
            ->sortable(['name', 'student_code', 'created_at'], default: '-created_at')
            ->cursorPaginate();

        return ApiResponse::success(StudentResource::collection($students));
    }

    public function store(StoreStudentRequest $request): JsonResponse
    {
        $student = Student::query()->create($request->validated());

        return ApiResponse::created(new StudentResource($student));
    }

    public function show(Student $student): JsonResponse
    {
        $this->authorize('view', $student);

        return ApiResponse::success(new StudentResource($student->loadCount('enrollments')));
    }

    public function update(UpdateStudentRequest $request, Student $student): JsonResponse
    {
        $student->update($request->validated());

        return ApiResponse::success(new StudentResource($student));
    }

    public function destroy(Student $student): JsonResponse
    {
        $this->authorize('delete', $student);

        $student->delete();

        return ApiResponse::noContent();
    }
}
