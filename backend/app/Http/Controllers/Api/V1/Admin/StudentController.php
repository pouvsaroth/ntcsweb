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
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class StudentController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Student::class);

        // cursorPaginate, not paginate: this is the table the platform is
        // explicitly designed to hold millions of rows in, and offset
        // pagination's COUNT(*) plus "skip N rows" gets slower with every
        // page — cursor pagination stays constant cost regardless of depth.
        $students = ApiQuery::for(Student::query()->withCount(['guardians', 'educations']), $request)
            ->searchable('first_name', 'last_name', 'english_name', 'student_code', 'email')
            ->filterable(['status'])
            ->sortable(['first_name', 'last_name', 'student_code', 'created_at'], default: '-created_at')
            ->cursorPaginate();

        return ApiResponse::success(StudentResource::collection($students));
    }

    public function store(StoreStudentRequest $request): JsonResponse
    {
        $student = DB::transaction(function () use ($request) {
            $student = Student::query()->create([
                ...$request->safe()->except(['photo', 'guardians', 'educations']),
                'photo_path' => $request->hasFile('photo') ? $this->storePhoto($request) : null,
            ]);

            $student->guardians()->createMany($request->safe()->input('guardians', []));
            $student->educations()->createMany($request->safe()->input('educations', []));

            return $student;
        });

        return ApiResponse::created(new StudentResource($student->load(['guardians', 'educations'])));
    }

    public function show(Student $student): JsonResponse
    {
        $this->authorize('view', $student);

        return ApiResponse::success(new StudentResource(
            $student->loadCount('enrollments')->load(['guardians', 'educations'])
        ));
    }

    public function update(UpdateStudentRequest $request, Student $student): JsonResponse
    {
        $previousPhotoPath = $student->photo_path;
        $newPhotoPath = $request->hasFile('photo') ? $this->storePhoto($request) : null;

        DB::transaction(function () use ($request, $student, $newPhotoPath) {
            $student->update([
                ...$request->safe()->except(['photo', 'guardians', 'educations']),
                ...($newPhotoPath !== null ? ['photo_path' => $newPhotoPath] : []),
            ]);

            // Whole-list replacement, not a merge — same convention as
            // SchoolClass's weekly schedule. Omitting the key entirely (vs.
            // sending an empty array) is what leaves the existing rows alone.
            if ($request->safe()->has('guardians')) {
                $student->guardians()->delete();
                $student->guardians()->createMany($request->safe()->input('guardians', []));
            }

            if ($request->safe()->has('educations')) {
                $student->educations()->delete();
                $student->educations()->createMany($request->safe()->input('educations', []));
            }
        });

        // Only removed once the new path is safely persisted — see
        // HomeSlideController::update() for why this ordering matters.
        if ($newPhotoPath !== null && $previousPhotoPath !== null) {
            Storage::disk('public')->delete($previousPhotoPath);
        }

        return ApiResponse::success(new StudentResource($student->fresh(['guardians', 'educations'])));
    }

    public function destroy(Student $student): JsonResponse
    {
        $this->authorize('delete', $student);

        // Soft-deleted only — Student::booted() removes the photo itself on
        // a *force* delete, so a mistaken removal stays recoverable. Guardian
        // and education rows are left in place (they hard-delete only via
        // the students table's FK cascade on a real, permanent removal).
        $student->delete();

        return ApiResponse::noContent();
    }

    private function storePhoto(StoreStudentRequest|UpdateStudentRequest $request): string
    {
        $tenant = $this->context->getOrFail();

        $path = $request->file('photo')->store($tenant->storagePath('students'), 'public');

        if ($path === false) {
            abort(500, 'Failed to store the uploaded photo.');
        }

        return $path;
    }
}
