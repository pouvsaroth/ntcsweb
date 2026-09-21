<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\ResetUserPasswordRequest;
use App\Http\Requests\Api\V1\Admin\StoreUserRequest;
use App\Http\Requests\Api\V1\Admin\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Services\Auth\UserProvisioningService;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditLogger;
use App\Support\Query\ApiQuery;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class UserController extends Controller
{
    public function __construct(
        private readonly UserProvisioningService $provisioning,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $users = ApiQuery::for(User::query()->inTenant($this->context->id())->with(['roles', 'student:id,user_id']), $request)
            ->searchable('name', 'email')
            ->filterable(['status'])
            ->sortable(['name', 'email', 'created_at'], default: '-created_at')
            ->paginate();

        return ApiResponse::success(UserResource::collection($users));
    }

    /**
     * Either links an existing, not-yet-linked Student (role forced to that
     * tenant's Student role) or creates a standalone account with an
     * explicit `role_id` — see StoreUserRequest's docblock for why those are
     * the only two shapes, and how the second one is guarded against
     * granting a role the acting admin doesn't outrank.
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        [$user, $temporaryPassword] = DB::transaction(function () use ($request) {
            $studentId = $request->validated('student_id');

            $role = $studentId !== null
                ? Role::query()->where('tenant_id', $this->context->idOrFail())->where('slug', Role::STUDENT)->firstOrFail()
                : Role::query()->findOrFail($request->validated('role_id'));

            $provisioned = $this->provisioning->provision([
                'name' => $request->validated('name'),
                'email' => $request->validated('email'),
                'phone' => $request->validated('phone'),
            ], $role);

            if ($studentId !== null) {
                Student::query()->whereKey($studentId)->update(['user_id' => $provisioned['user']->id]);
            }

            return [$provisioned['user'], $provisioned['temporary_password']];
        });

        return ApiResponse::success(
            new UserResource($user->load('roles')),
            'Created.',
            ['temporary_password' => $temporaryPassword],
            Response::HTTP_CREATED,
        );
    }

    /**
     * Profile fields plus, for a standalone (non-student-linked) account
     * only, a role reassignment — see UpdateUserRequest's docblock. A role
     * change replaces every role this user currently holds rather than
     * adding to them: unlike StaffController::update() (which only swaps the
     * one role tied to the old/new Position), this is the user's only role
     * source, so a plain sync is correct here.
     */
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        DB::transaction(function () use ($request, $user) {
            $user->update($request->safe()->only(['name', 'phone', 'email']));

            $roleId = $request->validated('role_id');

            if ($roleId !== null) {
                $newRole = Role::query()->findOrFail($roleId);
                $currentRoles = $user->roles()->get();

                if (! $currentRoles->contains(fn (Role $role) => $role->is($newRole))) {
                    if ($currentRoles->isNotEmpty()) {
                        $user->detachRoles(...$currentRoles->all());
                    }
                    $user->attachRoles($newRole);

                    $this->audit->log(
                        AuditAction::ROLE_CHANGE,
                        'Users',
                        $user,
                        old: ['role' => $currentRoles->pluck('name')->implode(', ') ?: '—'],
                        new: ['role' => $newRole->name],
                        description: "Changed user role for {$user->name} to {$newRole->name}",
                    );
                }
            }
        });

        return ApiResponse::success(new UserResource($user->load('roles')));
    }

    /**
     * A School Admin setting a new password for a student's or staff
     * member's login — see ResetUserPasswordRequest/UserPolicy::resetPassword()
     * for why this can never target the acting admin's own account (that's
     * PasswordController::change()'s job instead).
     */
    public function resetPassword(ResetUserPasswordRequest $request, User $user): JsonResponse
    {
        $user->forceFill([
            'password' => $request->string('password')->toString(),
            'remember_token' => Str::random(60),
        ])->save();

        // The target isn't the one making this request, so every existing
        // session of theirs is revoked outright — nothing to preserve.
        $user->tokens()->delete();
        $user->loginSessions()->delete();

        $this->audit->log(
            AuditAction::PASSWORD_CHANGE,
            'Users',
            $user,
            description: "Reset the password for {$user->name}",
        );

        return ApiResponse::success(message: __('Password updated.'));
    }

    /**
     * Clears every concurrent-device slot (AuthService::ensureNoOtherActiveDevice())
     * for a user who lost access to (or simply forgot to sign out of) one or
     * more of their devices — see UserPolicy::forceLogout() for who may do
     * this. This only frees them to log in again; it does not itself kill an
     * other device's existing browser session (Laravel's own session store
     * is driver-dependent — see the UserSession migration's docblock — so
     * there is no reliable way to reach into it from here). Revoking every
     * Sanctum token, however, is a real and immediate revocation.
     */
    public function forceLogout(User $user): JsonResponse
    {
        $this->authorize('forceLogout', $user);

        $user->tokens()->delete();
        $user->loginSessions()->delete();

        $this->audit->log(
            AuditAction::FORCE_LOGOUT,
            'Users',
            $user,
            description: "Signed {$user->name} out of every device",
        );

        return ApiResponse::success(message: __('Every device has been signed out.'));
    }
}
