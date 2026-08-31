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
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

final class StaffController extends Controller
{
    /**
     * A small, fixed palette of pleasant, readable-on-white colors — the
     * avatar background fallback for a staff member with no photo. Picked
     * deterministically (see profileColorFor()) rather than randomly, so the
     * same name always lands on the same color.
     */
    private const PROFILE_COLORS = [
        '#F87171', '#FB923C', '#FBBF24', '#4ADE80',
        '#2DD4BF', '#60A5FA', '#818CF8', '#F472B6',
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly UserProvisioningService $provisioning,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Staff::class);

        $staff = ApiQuery::for(Staff::query()->with(['position.role', 'user']), $request)
            ->searchable('first_name', 'last_name', 'employee_code', 'email')
            ->filterable(['status', 'position_id'])
            ->sortable(['first_name', 'last_name', 'employee_code', 'hire_date', 'created_at'], default: '-created_at')
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
        $fullName = trim("{$request->safe()->input('first_name')} {$request->safe()->input('last_name')}");

        [$staff, $temporaryPassword] = DB::transaction(function () use ($request, $fullName) {
            $position = Position::query()->with('role')->findOrFail($request->validated('position_id'));

            $provisioned = $this->provisioning->provision([
                'name' => $fullName,
                'email' => $request->validated('email'),
                'phone' => $request->validated('phone'),
            ], $position->role);

            $staff = Staff::query()->create($request->safe()->except(['photo', 'national_id_photo']));

            // Excluded from Fillable (see the Staff class docblock) — set
            // via forceFill exactly like `user_id`, never through mass
            // assignment, since none of these may ever be client-supplied.
            $staff->forceFill([
                'user_id' => $provisioned['user']->id,
                'photo_path' => $request->hasFile('photo') ? $this->storeFile($request, 'photo') : null,
                'national_id_photo_path' => $request->hasFile('national_id_photo')
                    ? $this->storeFile($request, 'national_id_photo')
                    : null,
                'profile_color' => $this->profileColorFor($fullName),
            ])->save();

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
        $previousPhotoPath = $staff->photo_path;
        $previousNationalIdPhotoPath = $staff->national_id_photo_path;

        $newPhotoPath = $request->hasFile('photo') ? $this->storeFile($request, 'photo') : null;
        $newNationalIdPhotoPath = $request->hasFile('national_id_photo')
            ? $this->storeFile($request, 'national_id_photo')
            : null;

        DB::transaction(function () use ($request, $staff, $newPhotoPath, $newNationalIdPhotoPath) {
            $previousPositionId = $staff->position_id;

            $staff->update($request->safe()->except(['photo', 'national_id_photo']));

            // Excluded from Fillable (see the Staff class docblock) — same
            // forceFill treatment as store(); only touched when a new file
            // actually came in, so an edit that doesn't replace either photo
            // never overwrites the existing path with null.
            if ($newPhotoPath !== null || $newNationalIdPhotoPath !== null) {
                $staff->forceFill([
                    ...($newPhotoPath !== null ? ['photo_path' => $newPhotoPath] : []),
                    ...($newNationalIdPhotoPath !== null ? ['national_id_photo_path' => $newNationalIdPhotoPath] : []),
                ])->save();
            }

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

        // Only removed once the new path is safely persisted — see
        // StudentController::update() for why this ordering matters.
        if ($newPhotoPath !== null && $previousPhotoPath !== null) {
            Storage::disk('public')->delete($previousPhotoPath);
        }

        if ($newNationalIdPhotoPath !== null && $previousNationalIdPhotoPath !== null) {
            Storage::disk('public')->delete($previousNationalIdPhotoPath);
        }

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

    private function storeFile(StoreStaffRequest|UpdateStaffRequest $request, string $field): string
    {
        $tenant = $this->context->getOrFail();

        $path = $request->file($field)->store($tenant->storagePath('staff'), 'public');

        if ($path === false) {
            abort(500, "Failed to store the uploaded {$field}.");
        }

        return $path;
    }

    /**
     * Deterministic, not random: the same name always lands on the same
     * palette entry, and it's computed once here at creation time — see the
     * class docblock on Staff for why it's never regenerated on update.
     */
    private function profileColorFor(string $fullName): string
    {
        $index = crc32($fullName) % count(self::PROFILE_COLORS);

        return self::PROFILE_COLORS[$index];
    }
}
