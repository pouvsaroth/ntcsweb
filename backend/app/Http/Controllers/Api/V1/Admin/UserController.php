<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreUserRequest;
use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Services\Auth\UserProvisioningService;
use App\Support\Query\ApiQuery;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class UserController extends Controller
{
    public function __construct(
        private readonly UserProvisioningService $provisioning,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $users = ApiQuery::for(User::query()->inTenant($this->context->id())->with('roles'), $request)
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
}
