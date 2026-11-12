<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\GeofenceResource;
use App\Http\Responses\ApiResponse;
use App\Models\Geofence;
use App\Models\Staff;
use App\Models\StaffGeofenceRule;
use App\Services\StaffAttendance\GeofenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * HRM > Attendance & Time > Geofences: the places staff check in / out
 * from (circles and polygons), and staff members' own rules — anywhere,
 * or only some zones. Read with staff-attendance.view; Add / Update /
 * Delete with geofences.*.
 */
final class GeofenceController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Geofence::class);

        return ApiResponse::success(GeofenceResource::collection(Geofence::query()->orderBy('name')->get()));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', [Geofence::class, Geofence::class]);

        $geofence = Geofence::query()->create($this->validated($request));

        return ApiResponse::created(new GeofenceResource($geofence));
    }

    public function update(Request $request, Geofence $geofence): JsonResponse
    {
        $this->authorize('update', $geofence);

        $geofence->update($this->validated($request));

        return ApiResponse::success(new GeofenceResource($geofence));
    }

    public function destroy(Geofence $geofence): JsonResponse
    {
        $this->authorize('delete', $geofence);

        $geofence->delete();

        return ApiResponse::noContent();
    }

    // --- Staff rules -------------------------------------------------------------

    public function rules(): JsonResponse
    {
        $this->authorize('viewAny', Geofence::class);

        $rules = StaffGeofenceRule::query()->with('staff')->get()
            ->sortBy(fn (StaffGeofenceRule $rule) => $rule->staff?->fullName())
            ->values();

        return ApiResponse::success($rules->map(fn (StaffGeofenceRule $rule) => $this->ruleData($rule))->all());
    }

    /** Sets one staff member's rule (replacing any they had). */
    public function saveRule(Request $request): JsonResponse
    {
        $data = $request->validate([
            'staff_id' => ['required', 'integer', Rule::exists(Staff::class, 'id')],
            'mode' => ['required', Rule::in([StaffGeofenceRule::ANYWHERE, StaffGeofenceRule::ZONES])],
            'geofence_ids' => ['nullable', 'array', Rule::requiredIf(fn () => $request->input('mode') === StaffGeofenceRule::ZONES), 'min:1'],
            'geofence_ids.*' => ['integer', 'distinct', Rule::exists(Geofence::class, 'id')],
        ]);

        $rule = StaffGeofenceRule::query()->firstOrNew(['staff_id' => $data['staff_id']]);
        $this->authorize($rule->exists ? 'update' : 'create', $rule->exists ? $rule : [StaffGeofenceRule::class, StaffGeofenceRule::class]);

        $rule->fill([
            'mode' => $data['mode'],
            'geofence_ids' => $data['mode'] === StaffGeofenceRule::ZONES ? array_values(array_map('intval', $data['geofence_ids'])) : null,
        ])->save();

        return ApiResponse::success($this->ruleData($rule->load('staff')));
    }

    public function destroyRule(StaffGeofenceRule $rule): JsonResponse
    {
        $this->authorize('delete', $rule);

        $rule->delete();

        return ApiResponse::noContent();
    }

    /** @return array<string, mixed> */
    private function ruleData(StaffGeofenceRule $rule): array
    {
        return [
            'id' => $rule->id,
            'staff_id' => $rule->staff_id,
            'staff_name' => $rule->staff?->fullName(),
            'employee_code' => $rule->staff?->employee_code,
            'mode' => $rule->mode,
            'geofence_ids' => array_map('intval', $rule->geofence_ids ?? []),
        ];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'shape' => ['required', Rule::in([Geofence::CIRCLE, Geofence::POLYGON])],
            'latitude' => ['required_if:shape,circle', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['required_if:shape,circle', 'nullable', 'numeric', 'between:-180,180'],
            'radius_m' => ['required_if:shape,circle', 'nullable', 'integer', 'min:10', 'max:20000'],
            'points' => ['required_if:shape,polygon', 'nullable', 'array', 'min:3', 'max:200'],
            'points.*' => ['array', 'size:2'],
            'points.*.0' => ['required', 'numeric', 'between:-90,90'],
            'points.*.1' => ['required', 'numeric', 'between:-180,180'],
            'is_active' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:2000'],
        ], [
            'points.required_if' => __('Draw the area: tap at least 3 corners on the map.'),
            'points.min' => __('Draw the area: tap at least 3 corners on the map.'),
            'latitude.required_if' => __('Put the pin on the map.'),
        ]);

        if ($data['shape'] === Geofence::POLYGON) {
            $points = array_map(fn (array $p) => [round((float) $p[0], 7), round((float) $p[1], 7)], $data['points']);
            [$lat, $lng] = GeofenceService::middle($points);

            return [...$data, 'points' => $points, 'latitude' => $lat, 'longitude' => $lng, 'radius_m' => null];
        }

        return [...$data, 'points' => null];
    }
}
