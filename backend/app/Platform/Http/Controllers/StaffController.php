<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Platform\Http\Resources\StaffResource;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformStaff;
use App\Platform\Support\PlatformAudit;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Back-office accounts. A new account gets a temporary password, shown once
 * here and never again; its owner must replace it and enrol 2FA at first
 * sign-in. There must always be at least one active Owner.
 */
final class StaffController extends Controller
{
    public function __construct(private readonly PlatformAudit $audit) {}

    public function index(Request $request): JsonResponse
    {
        $staff = ApiQuery::for(PlatformStaff::query()->with('roles'), $request)
            ->searchable('name', 'email')
            ->filterable(['status'])
            ->sortable(['name', 'email', 'last_login_at', 'created_at'], default: 'name')
            ->paginate();

        return ApiResponse::success(StaffResource::collection($staff));
    }

    public function show(PlatformStaff $staff): JsonResponse
    {
        return ApiResponse::success(new StaffResource($staff->load('roles')));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique(PlatformStaff::class, 'email')],
            'roles' => ['array'],
            'roles.*' => ['integer', Rule::exists(PlatformRole::class, 'id')],
        ]);

        $password = Str::password(16, symbols: false);

        $staff = DB::connection((new PlatformStaff)->getConnectionName())->transaction(function () use ($data, $password) {
            $staff = new PlatformStaff(['name' => $data['name'], 'email' => Str::lower($data['email'])]);
            $staff->forceFill([
                'password' => $password,
                'status' => PlatformStaff::STATUS_ACTIVE,
                'must_change_password' => true,
            ])->save();
            $staff->roles()->sync($data['roles'] ?? []);

            $this->audit->record('staff.created', $staff, new: [
                'name' => $staff->name,
                'email' => $staff->email,
                'roles' => $data['roles'] ?? [],
            ]);

            return $staff;
        });

        return ApiResponse::created([
            'staff' => new StaffResource($staff->load('roles')),
            'temporary_password' => $password,
        ]);
    }

    public function update(Request $request, PlatformStaff $staff): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in([PlatformStaff::STATUS_ACTIVE, PlatformStaff::STATUS_DISABLED])],
            'roles' => ['sometimes', 'array'],
            'roles.*' => ['integer', Rule::exists(PlatformRole::class, 'id')],
        ]);

        if ($staff->is($request->user('staff')) && ($data['status'] ?? null) === PlatformStaff::STATUS_DISABLED) {
            throw ValidationException::withMessages(['status' => __('You cannot disable your own account.')]);
        }

        $old = ['name' => $staff->name, 'status' => $staff->status, 'roles' => $staff->roles->pluck('id')->sort()->values()->all()];

        DB::connection($staff->getConnectionName())->transaction(function () use ($staff, $data, $old) {
            $staff->fill(array_intersect_key($data, ['name' => true]));
            if (isset($data['status'])) {
                $staff->forceFill(['status' => $data['status']]);
            }
            $staff->save();

            if (array_key_exists('roles', $data)) {
                $staff->roles()->sync($data['roles']);
            }

            $this->ensureAnOwnerRemains();

            $staff->load('roles');
            $this->audit->record('staff.updated', $staff, $old, [
                'name' => $staff->name,
                'status' => $staff->status,
                'roles' => $staff->roles->pluck('id')->sort()->values()->all(),
            ]);
        });

        return ApiResponse::success(new StaffResource($staff));
    }

    /** Lost phone: the next sign-in enrols again. Signs the account out on its next request. */
    public function resetTwoFactor(PlatformStaff $staff): JsonResponse
    {
        DB::connection($staff->getConnectionName())->transaction(function () use ($staff) {
            $staff->forceFill([
                'two_factor_secret' => null,
                'two_factor_confirmed_at' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_last_step' => null,
            ])->save();
            $this->audit->record('staff.two_factor_reset', $staff);
        });

        return ApiResponse::success(new StaffResource($staff->load('roles')));
    }

    /** Forgotten password: a new temporary one, shown once, to be replaced at sign-in. */
    public function resetPassword(PlatformStaff $staff): JsonResponse
    {
        $password = Str::password(16, symbols: false);

        DB::connection($staff->getConnectionName())->transaction(function () use ($staff, $password) {
            $staff->forceFill(['password' => $password, 'must_change_password' => true])->save();
            $this->audit->record('staff.password_reset', $staff);
        });

        return ApiResponse::success([
            'staff' => new StaffResource($staff->load('roles')),
            'temporary_password' => $password,
        ]);
    }

    private function ensureAnOwnerRemains(): void
    {
        $owners = PlatformStaff::query()
            ->where('status', PlatformStaff::STATUS_ACTIVE)
            ->whereHas('roles', fn ($q) => $q->where('is_owner', true))
            ->count();

        if ($owners === 0) {
            throw ValidationException::withMessages(['roles' => __('At least one active Owner must remain.')]);
        }
    }
}
