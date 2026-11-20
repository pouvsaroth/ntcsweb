<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\User;
use App\Services\Academic\ParentAccessService;
use App\Support\Authorization\ClassAccess;
use App\Support\Authorization\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Students > a student > Guardians: Parent portal logins. Seeing them needs
 * students.view; giving, removing or resetting needs students.update.
 */
final class ParentAccessController extends Controller
{
    public function __construct(
        private readonly ParentAccessService $parents,
        private readonly ClassAccess $classAccess,
    ) {}

    public function index(Request $request, Student $student): JsonResponse
    {
        $this->allow($request, $student, Permissions::STUDENTS_VIEW);

        return ApiResponse::success($this->parents->overview($student));
    }

    public function store(Request $request, Student $student): JsonResponse
    {
        $this->allow($request, $student, Permissions::STUDENTS_UPDATE);
        $data = $request->validate(['guardian_id' => ['required', 'integer']]);
        $guardian = StudentGuardian::query()->where('student_id', $student->id)->findOrFail($data['guardian_id']);

        $result = $this->parents->give($student, $guardian, $request->user());

        return ApiResponse::success([
            'parent' => $this->parents->present($result['user']),
            // Shown once to the staff member, to pass on to the parent.
            'temporary_password' => $result['temporary_password'],
            'new_login' => $result['new_login'],
            'children' => $result['children'],
        ]);
    }

    public function destroy(Request $request, Student $student, int $user): JsonResponse
    {
        $this->allow($request, $student, Permissions::STUDENTS_UPDATE);
        $this->parents->revoke($student, $this->parentOf($student, $user), $request->user());

        return ApiResponse::success(['removed' => true]);
    }

    public function resetPassword(Request $request, Student $student, int $user): JsonResponse
    {
        $this->allow($request, $student, Permissions::STUDENTS_UPDATE);

        return ApiResponse::success(['temporary_password' => $this->parents->resetPassword($this->parentOf($student, $user), $request->user())]);
    }

    private function parentOf(Student $student, int $id): User
    {
        abort_unless($student->parentLinks()->where('user_id', $id)->exists(), 404);

        /** @var User */
        return User::query()->inTenant(request()->user()->tenant_id)->findOrFail($id);
    }

    private function allow(Request $request, Student $student, string $permission): void
    {
        abort_unless($request->user()->hasPermission($permission), 403);
        abort_unless($this->classAccess->canSeeStudent($request->user(), $student), 403);
    }
}
