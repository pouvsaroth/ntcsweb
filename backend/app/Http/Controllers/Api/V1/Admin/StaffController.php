<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreStaffRequest;
use App\Http\Requests\Api\V1\Admin\UpdateStaffRequest;
use App\Http\Resources\StaffResource;
use App\Http\Responses\ApiResponse;
use App\Models\Position;
use App\Models\Staff;
use App\Services\Auth\UserProvisioningService;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditLogger;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class StaffController extends Controller
{
    public function __construct(
        private readonly UserProvisioningService $provisioning,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Staff::class);

        $staff = ApiQuery::for(Staff::query()->with(['position.role', 'user']), $request)
            ->searchable('name', 'employee_code', 'email')
            ->filterable(['status', 'position_id'])
            ->sortable(['name', 'employee_code', 'hire_date', 'created_at'], default: '-created_at')
            ->paginate();

        return ApiResponse::success(StaffResource::collection($staff));
    }

    /**
     * Position is the sole source of truth for the new account's role — the
     * request never carries one (see StoreStaffRequest's docblock). This is
     * what makes "select Position = Accountant" the only way to end up with
     * the Accountant role: there is no other input this method reads.
     */
    public function store(StoreStaffRequest $request): JsonResponse
    {
        [$staff, $temporaryPassword] = DB::transaction(function () use ($request) {
            $position = Position::query()->with('role')->findOrFail($request->validated('position_id'));

            $provisioned = $this->provisioning->provision([
                'name' => $request->validated('name'),
                'email' => $request->validated('email'),
                'phone' => $request->validated('phone'),
            ], $position->role);

            $staff = Staff::query()->create($request->validated());
            $staff->forceFill(['user_id' => $provisioned['user']->id])->save();

            return [$staff, $provisioned['temporary_password']];
        });

        return ApiResponse::success(
            new StaffResource($staff->load(['position.role', 'user'])),
            'Staff member created successfully.',
            ['temporary_password' => $temporaryPassword],
            Response::HTTP_CREATED,
        );
    }

    public function show(Staff $staff): JsonResponse
    {
        $this->authorize('view', $staff);

        return ApiResponse::success(new StaffResource($staff->load(['position.role', 'user'])));
    }

    /**
     * A Position change re-syncs the linked user's role: the old position's
     * role is detached and the new one attached — never a blanket
     * `sync()`, so any other role the user separately holds is left alone.
     */
    public function update(UpdateStaffRequest $request, Staff $staff): JsonResponse
    {
        DB::transaction(function () use ($request, $staff) {
            $previousPositionId = $staff->position_id;

            $staff->update($request->validated());

            $positionChanged = $request->safe()->has('position_id')
                && (int) $request->validated('position_id') !== $previousPositionId;

            if ($positionChanged && $staff->user_id !== null) {
                $oldRole = Position::query()->with('role')->find($previousPositionId)?->role;
                $newRole = Position::query()->with('role')->find($staff->position_id)?->role;

                if ($oldRole !== null && $newRole !== null && $oldRole->isNot($newRole)) {
                    $staff->user->detachRoles($oldRole);
                    $staff->user->attachRoles($newRole);

                    // A relationship change, invisible to Auditable's column
                    // diffing — the one place this needs an explicit call.
                    $this->audit->log(
                        AuditAction::ROLE_CHANGE,
                        'Users',
                        $staff->user,
                        old: ['role' => $oldRole->name],
                        new: ['role' => $newRole->name],
                        description: "Changed user role from {$oldRole->name} to {$newRole->name}",
                    );
                }
            }
        });

        return ApiResponse::success(new StaffResource($staff->fresh(['position.role', 'user'])));
    }

    /**
     * Soft-delete only — mirrors Teacher/Student. The linked User account is
     * left untouched: it may hold other history, and deletion is a separate,
     * explicit decision on the Users screen, never an automatic side effect.
     */
    public function destroy(Staff $staff): JsonResponse
    {
        $this->authorize('delete', $staff);

        $staff->delete();

        return ApiResponse::noContent();
    }
}
