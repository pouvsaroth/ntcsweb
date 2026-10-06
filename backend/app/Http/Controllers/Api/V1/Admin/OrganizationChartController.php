<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Staff;
use App\Models\Team;
use Illuminate\Http\JsonResponse;

/**
 * Everything HRM > Organization Management's Reporting manager and
 * Organization hierarchy tabs draw, in one read: the active branches,
 * departments and teams (with their parent ids) and the staff still working
 * here (with their placement and manager). Flat lists — the frontend builds
 * both trees from the same payload.
 */
final class OrganizationChartController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $this->authorize('viewAny', Branch::class);

        return ApiResponse::success([
            'branches' => Branch::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'departments' => Department::query()->where('is_active', true)->orderBy('code')->get(['id', 'branch_id', 'code', 'name']),
            'teams' => Team::query()->where('is_active', true)->orderBy('code')->get(['id', 'department_id', 'code', 'name']),
            'staff' => Staff::query()
                ->with('position:id,name')
                ->whereIn('status', Staff::STATUSES_WORKING)
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->get()
                ->map(fn (Staff $staff) => [
                    'id' => $staff->id,
                    'employee_code' => $staff->employee_code,
                    'full_name' => $staff->fullName(),
                    'position' => $staff->position?->name,
                    'photo_url' => $staff->photoUrl(),
                    'profile_color' => $staff->profile_color,
                    'branch_id' => $staff->branch_id,
                    'department_id' => $staff->department_id,
                    'team_id' => $staff->team_id,
                    'reports_to_staff_id' => $staff->reports_to_staff_id,
                ]),
        ]);
    }
}
