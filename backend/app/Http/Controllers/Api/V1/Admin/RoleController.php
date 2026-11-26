<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreRoleRequest;
use App\Http\Requests\Api\V1\Admin\UpdateRoleRequest;
use App\Http\Resources\RoleResource;
use App\Http\Responses\ApiResponse;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Authorization\PermissionRegistry;
use App\Support\Query\ApiQuery;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RoleController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Role::class);

        // `with('permissions')`, not `withCount` alone: the frontend's edit
        // action needs each role's current permission set to pre-check the
        // matrix without a second request per row.
        $roles = ApiQuery::for(Role::query()->visibleTo($request->user())->withCount('users')->with('permissions'), $request)
            ->searchable('name')
            ->sortable(['name', 'level', 'created_at'], default: '-level')
            ->paginate();

        return ApiResponse::success(RoleResource::collection($roles));
    }

    /**
     * `tenant_id`/`is_system`/`slug` are all deliberately absent from
     * Role::$fillable except `slug` — see the model docblock — so the ones
     * that are guarded go through forceFill here, the one trusted place a
     * tenant-scoped, non-system role gets minted from request input.
     */
    public function store(StoreRoleRequest $request): JsonResponse
    {
        $role = new Role($request->safe()->only(['name', 'description', 'level', 'data_scope']));

        $role->forceFill([
            'tenant_id' => $this->context->idOrFail(),
            'slug' => Str::slug($request->validated('name')),
            'is_system' => false,
        ]);

        $role->save();

        if ($request->safe()->has('permissions')) {
            $this->syncPermissions($role, $request->validated('permissions'));
        }

        return ApiResponse::created(new RoleResource($role->load('permissions')));
    }

    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        $role->fill($request->safe()->only(['name', 'description', 'level', 'data_scope']));

        // A built-in role keeps its slug when renamed: the code looks it up by slug.
        if ($request->safe()->has('name') && ! $role->is_system) {
            $role->forceFill(['slug' => Str::slug($request->validated('name'))]);
        }

        $role->save();

        if ($request->safe()->has('permissions')) {
            $this->syncPermissions($role, $request->validated('permissions'));
        }

        return ApiResponse::success(new RoleResource($role->load('permissions')));
    }

    public function destroy(Role $role): JsonResponse
    {
        $this->authorize('delete', $role);

        // Refuse while the role is in use: deleting would silently strip it
        // from its users, and positions point at it without a foreign key.
        $users = $role->users()->count();
        $positions = $role->positions()->count();
        if ($users > 0 || $positions > 0) {
            throw ValidationException::withMessages([
                'role' => __('This role is still used by :users users and :positions positions. Give them another role first.', ['users' => $users, 'positions' => $positions]),
            ]);
        }

        $role->delete();

        return ApiResponse::noContent();
    }

    /**
     * @param  list<string>  $slugs
     */
    private function syncPermissions(Role $role, array $slugs): void
    {
        $ids = Permission::query()->whereIn('slug', $slugs)->pluck('id');

        $role->permissions()->sync($ids);

        // Role::saved already invalidated, but before this sync: a request in
        // between could re-cache the old set for the cache TTL (15 minutes).
        app(PermissionRegistry::class)->invalidate($role->tenant_id);
    }
}
