<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreStudentRequest;
use App\Http\Requests\Api\V1\Admin\UpdateStudentRequest;
use App\Http\Resources\StudentResource;
use App\Http\Responses\ApiResponse;
use App\Models\Role;
use App\Models\Student;
use App\Services\Academic\StudentIdGenerator;
use App\Services\Auth\UserProvisioningService;
use App\Support\Query\ApiQuery;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

final class StudentController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly UserProvisioningService $provisioning,
        private readonly StudentIdGenerator $studentIdGenerator,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Student::class);

        // paginate(), not cursorPaginate(): this table was originally built
        // cursor-only on the assumption it would need to scale to millions
        // of rows, but the admin UI needs numbered, jump-to-page-N
        // pagination (like every other list screen), which cursor
        // pagination structurally cannot provide — there's no "page 4" when
        // you only ever know the next/previous opaque token. Deliberately
        // traded away the constant-cost-at-any-depth guarantee for a
        // realistic single school's scale (thousands of students, not
        // millions), where offset pagination's COUNT(*)/OFFSET cost is
        // negligible. Revisit if a tenant's real row count ever approaches
        // the point where that trade stops being negligible.
        $students = ApiQuery::for(Student::query()->withCount(['guardians', 'educations']), $request)
            ->searchable('first_name', 'last_name', 'english_name', 'student_code', 'email')
            ->filterable(['status'])
            ->sortable(['first_name', 'last_name', 'student_code', 'created_at'], default: '-created_at')
            ->paginate();

        return ApiResponse::success(StudentResource::collection($students));
    }

    /**
     * A `user_id` given in the request links an already-existing account
     * (unchanged, pre-existing behavior); otherwise a new one is
     * auto-provisioned here and given the tenant's Student role — never a
     * role taken from the request, which doesn't accept one at all.
     */
    public function store(StoreStudentRequest $request): JsonResponse
    {
        [$student, $temporaryPassword] = DB::transaction(function () use ($request) {
            $temporaryPassword = null;
            $userId = $request->safe()->input('user_id');

            if ($userId === null) {
                $studentRole = Role::query()
                    ->where('tenant_id', $this->context->idOrFail())
                    ->where('slug', Role::STUDENT)
                    ->firstOrFail();

                $provisioned = $this->provisioning->provision([
                    'name' => trim("{$request->safe()->input('first_name')} {$request->safe()->input('last_name')}"),
                    'email' => $request->safe()->input('email'),
                    'phone' => $request->safe()->input('phone'),
                ], $studentRole);

                $userId = $provisioned['user']->id;
                $temporaryPassword = $provisioned['temporary_password'];
            }

            // Generated here, inside the same transaction as everything
            // else this request writes — never from request input (see
            // StoreStudentRequest, which has no student_code rule at all).
            $studentCode = $this->studentIdGenerator->next($this->context->getOrFail());

            $student = Student::query()->create([
                ...$request->safe()->except(['photo', 'guardians', 'educations', 'user_id']),
                'student_code' => $studentCode,
                'user_id' => $userId,
                'photo_path' => $request->hasFile('photo') ? $this->storePhoto($request) : null,
            ]);

            $student->guardians()->createMany($request->safe()->input('guardians', []));
            $student->educations()->createMany($request->safe()->input('educations', []));

            return [$student, $temporaryPassword];
        });

        // `data` stays exactly the StudentResource shape every other
        // consumer of this endpoint already expects; the one-time password
        // (present only when this call auto-provisioned a new account, i.e.
        // no `user_id` was given) rides in `meta` instead of reshaping `data`.
        return ApiResponse::success(
            new StudentResource($student->load(['guardians', 'educations'])),
            'Created.',
            $temporaryPassword !== null ? ['temporary_password' => $temporaryPassword] : [],
            Response::HTTP_CREATED,
        );
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
