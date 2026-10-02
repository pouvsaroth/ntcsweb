<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\SaveApprovalGroupRequest;
use App\Http\Resources\ApprovalGroupResource;
use App\Http\Responses\ApiResponse;
use App\Models\ApprovalFlowStep;
use App\Models\ApprovalGroup;
use App\Models\User;
use App\Services\Approvals\ApprovalFlow;
use App\Support\Query\ApiQuery;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Approval Flow → Groups: named groups of users, each with its member list
 * (see ApprovalGroup). Members are always saved as the whole list at once.
 */
final class ApprovalGroupController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ApprovalGroup::class);

        $groups = ApiQuery::for(ApprovalGroup::query()->with('members'), $request)
            ->searchable('name', 'description')
            ->sortable(['name', 'created_at'], default: 'name')
            ->paginate();

        $this->withMembers($groups->getCollection()->all());

        return ApiResponse::success(ApprovalGroupResource::collection($groups));
    }

    public function store(SaveApprovalGroupRequest $request): JsonResponse
    {
        $group = DB::connection('tenant')->transaction(function () use ($request) {
            $group = ApprovalGroup::query()->create($request->safe()->only(['name', 'description']));
            $this->syncMembers($group, $request->validated('user_ids'));

            return $group;
        });

        return ApiResponse::created(new ApprovalGroupResource($this->fresh($group)));
    }

    public function show(ApprovalGroup $approvalGroup): JsonResponse
    {
        $this->authorize('view', $approvalGroup);

        return ApiResponse::success(new ApprovalGroupResource($this->fresh($approvalGroup)));
    }

    public function update(SaveApprovalGroupRequest $request, ApprovalGroup $approvalGroup): JsonResponse
    {
        DB::connection('tenant')->transaction(function () use ($request, $approvalGroup) {
            $approvalGroup->update($request->safe()->only(['name', 'description']));
            $this->syncMembers($approvalGroup, $request->validated('user_ids'));
        });

        return ApiResponse::success(new ApprovalGroupResource($this->fresh($approvalGroup)));
    }

    public function destroy(ApprovalGroup $approvalGroup): JsonResponse
    {
        $this->authorize('delete', $approvalGroup);

        // Soft delete leaves the flow step's foreign key intact, so check here
        // rather than relying on the database's restrict.
        if (ApprovalFlowStep::query()->where('approval_group_id', $approvalGroup->id)->exists()) {
            throw ValidationException::withMessages(['group' => 'This group is used in an approval flow. Remove it from Flow Setting first.']);
        }

        $approvalGroup->delete();

        return ApiResponse::noContent();
    }

    /** Every active user of this school, for the member picker. */
    public function users(): JsonResponse
    {
        $this->authorize('viewAny', ApprovalGroup::class);

        $users = User::query()
            ->inTenant($this->context->id())
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email]);

        return ApiResponse::success($users);
    }

    /** @param  list<int|string>  $userIds */
    private function syncMembers(ApprovalGroup $group, array $userIds): void
    {
        $userIds = array_map('intval', $userIds);

        $group->members()->whereNotIn('user_id', $userIds)->delete();
        ApprovalFlow::invalidate();

        foreach ($userIds as $userId) {
            $group->members()->firstOrCreate(['user_id' => $userId]);
        }
    }

    private function fresh(ApprovalGroup $group): ApprovalGroup
    {
        $group = $group->fresh(['members']) ?? $group;
        $this->withMembers([$group]);

        return $group;
    }

    /**
     * Looks up every member's user in one query (users live in the central
     * database) and sets them on each group as `member_users`, in the order
     * they were added. A member whose user was deleted is simply left out.
     *
     * @param  list<ApprovalGroup>  $groups
     */
    private function withMembers(array $groups): void
    {
        $userIds = collect($groups)->flatMap(fn (ApprovalGroup $group) => $group->memberUserIds())->unique()->values();

        $users = User::query()
            ->inTenant($this->context->id())
            ->whereIn('id', $userIds)
            ->get(['id', 'name', 'email'])
            ->keyBy('id');

        foreach ($groups as $group) {
            $group->setAttribute('member_users', $group->memberUserIds()
                ->map(fn (int $id) => $users->get($id))
                ->filter()
                ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email])
                ->values()
                ->all());
        }
    }
}
