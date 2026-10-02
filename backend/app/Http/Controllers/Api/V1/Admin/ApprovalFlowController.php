<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\ApprovalFlowStep;
use App\Models\ApprovalGroup;
use App\Services\Approvals\ApprovalFlow;
use App\Support\Approvals\DocumentType;
use App\Support\Authorization\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Approval Flow → Flow Setting: for each approvable item, the ordered
 * groups that approve it (see ApprovalFlow for how they're enforced).
 * Shares approval-groups.manage with the Groups tab.
 */
final class ApprovalFlowController extends Controller
{
    /** Every item, with its steps in order (an empty list = no flow). */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $steps = ApprovalFlowStep::query()->with('group.members')->orderBy('step_order')->get()->groupBy('document_type');

        $data = collect(DocumentType::all())->map(fn (string $type) => [
            'document_type' => $type,
            'steps' => $steps->get($type, collect())->map(fn (ApprovalFlowStep $step) => [
                'step_order' => $step->step_order,
                'group' => [
                    'id' => $step->approval_group_id,
                    'name' => $step->group?->name,
                    'member_count' => $step->group?->members->count() ?? 0,
                ],
            ])->values(),
        ]);

        return ApiResponse::success($data);
    }

    /** Replaces one item's whole flow — `group_ids` in approval order; empty = remove the flow. */
    public function update(Request $request, string $documentType): JsonResponse
    {
        $this->authorizeManage($request);
        abort_unless(in_array($documentType, DocumentType::all(), true), 404);

        $validated = $request->validate([
            'group_ids' => ['present', 'array', 'max:20'],
            'group_ids.*' => ['integer', 'distinct', Rule::exists(ApprovalGroup::class, 'id')->whereNull('deleted_at')],
        ]);

        DB::connection('tenant')->transaction(function () use ($documentType, $validated) {
            ApprovalFlowStep::query()->where('document_type', $documentType)->delete();
            ApprovalFlow::invalidate();

            foreach (array_values($validated['group_ids']) as $index => $groupId) {
                ApprovalFlowStep::query()->create([
                    'document_type' => $documentType,
                    'step_order' => $index + 1,
                    'approval_group_id' => $groupId,
                ]);
            }
        });

        return $this->index($request);
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()?->hasPermission(Permissions::APPROVAL_GROUPS_MANAGE), 403);
    }
}
