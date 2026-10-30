<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Staff;
use App\Models\StaffEducation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * HRM > Staff > Education (docs/hrm/staff-education.md): a staff member's
 * schools and universities, many each. Reading follows staff.view, changing
 * follows staff.update (StaffPolicy).
 */
final class StaffEducationController extends Controller
{
    /** Newest first: still studying, then by end year, then start year. */
    public function index(Staff $staff): JsonResponse
    {
        $this->authorize('view', $staff);

        $rows = $staff->educations()
            ->orderByRaw('end_year IS NOT NULL')
            ->orderByDesc('end_year')
            ->orderByDesc('start_year')
            ->orderByDesc('id')
            ->get();

        return ApiResponse::success($rows->map(fn (StaffEducation $e) => $this->present($e))->values());
    }

    public function store(Request $request, Staff $staff): JsonResponse
    {
        $this->authorize('update', $staff);
        $education = $staff->educations()->create($this->validated($request));

        return ApiResponse::created($this->present($education));
    }

    public function update(Request $request, Staff $staff, StaffEducation $education): JsonResponse
    {
        $this->authorize('update', $staff);
        abort_unless($education->staff_id === $staff->id, 404);
        $education->update($this->validated($request));

        return ApiResponse::success($this->present($education));
    }

    public function destroy(Staff $staff, StaffEducation $education): JsonResponse
    {
        $this->authorize('update', $staff);
        abort_unless($education->staff_id === $staff->id, 404);
        $education->delete();

        return ApiResponse::success(null);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $maxYear = (int) now()->year + 10;
        $data = $request->validate([
            'level' => ['required', Rule::in(StaffEducation::LEVELS)],
            'institution' => ['required', 'string', 'max:200'],
            'major' => ['nullable', 'string', 'max:200'],
            'country' => ['nullable', 'string', 'max:100'],
            'start_year' => ['nullable', 'integer', 'min:1900', 'max:'.$maxYear],
            'end_year' => ['nullable', 'integer', 'min:1900', 'max:'.$maxYear, ...($request->filled('start_year') ? ['gte:start_year'] : [])],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        foreach (['institution', 'major', 'country', 'note'] as $key) {
            if (array_key_exists($key, $data) && is_string($data[$key])) {
                $data[$key] = trim($data[$key]) ?: null;
            }
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function present(StaffEducation $e): array
    {
        return $e->only(['id', 'staff_id', 'level', 'institution', 'major', 'country', 'start_year', 'end_year', 'note']);
    }
}
