<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Platform\Http\Resources\RoleResource;
use App\Platform\Models\PlatformRole;
use App\Platform\Support\PlatformAudit;
use App\Platform\Support\PlatformPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Platform roles and the permission tree they are edited against. The Owner
 * role is read-only here: it always holds the whole catalogue.
 */
final class RoleController extends Controller
{
    public function __construct(private readonly PlatformAudit $audit) {}

    public function index(): JsonResponse
    {
        $roles = PlatformRole::query()->withCount('staff')->orderByDesc('is_owner')->orderBy('name')->get();

        return ApiResponse::success(RoleResource::collection($roles));
    }

    public function permissions(): JsonResponse
    {
        return ApiResponse::success(PlatformPermissions::tree());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $role = $this->transaction(function () use ($data) {
            $role = PlatformRole::query()->create($data);
            $this->audit->record('role.created', $role, new: $data);

            return $role;
        });

        return ApiResponse::created(new RoleResource($role->loadCount('staff')));
    }

    public function update(Request $request, PlatformRole $role): JsonResponse
    {
        abort_if($role->is_owner, 409, __('The Owner role cannot be changed.'));

        $data = $this->validated($request, $role);
        $old = $role->only(['name', 'description', 'permissions']);

        $this->transaction(function () use ($role, $data, $old) {
            $role->update($data);
            $this->audit->record('role.updated', $role, $old, $role->only(['name', 'description', 'permissions']));
        });

        return ApiResponse::success(new RoleResource($role->loadCount('staff')));
    }

    public function destroy(PlatformRole $role): JsonResponse
    {
        abort_if($role->is_owner, 409, __('The Owner role cannot be removed.'));

        $this->transaction(function () use ($role) {
            $this->audit->record('role.deleted', $role, $role->only(['name', 'description', 'permissions']));
            $role->delete();
        });

        return ApiResponse::noContent();
    }

    /** A new role holding the same permissions — the usual start for a narrower one. */
    public function copy(Request $request, PlatformRole $role): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique(PlatformRole::class, 'name')],
        ]);

        $copy = $this->transaction(function () use ($role, $data) {
            $copy = PlatformRole::query()->create([
                'name' => $data['name'],
                'description' => $role->description,
                'permissions' => $role->grantedPermissions(),
            ]);
            $this->audit->record('role.copied', $copy, new: ['from' => $role->id, 'name' => $copy->name]);

            return $copy;
        });

        return ApiResponse::created(new RoleResource($copy->loadCount('staff')));
    }

    /** @return array{name: string, description: ?string, permissions: list<string>} */
    private function validated(Request $request, ?PlatformRole $role = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique(PlatformRole::class, 'name')->ignore($role)],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::in(PlatformPermissions::keys())],
        ]);

        $data['permissions'] = array_values(array_unique($data['permissions']));
        $data['description'] ??= null;

        return $data;
    }

    private function transaction(\Closure $callback): mixed
    {
        return DB::connection((new PlatformRole)->getConnectionName())->transaction($callback);
    }
}
