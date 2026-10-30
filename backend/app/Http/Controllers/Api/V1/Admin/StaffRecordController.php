<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Staff;
use App\Support\Staff\StaffRecordKinds;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The list-style tabs of the staff form (docs/hrm/staff-profile-tabs.md):
 * `staff/{staff}/records/{kind}` for each kind in StaffRecordKinds.
 * Reading follows staff.view, changing follows staff.update (StaffPolicy).
 */
final class StaffRecordController extends Controller
{
    public function index(Staff $staff, string $kind): JsonResponse
    {
        $this->authorize('view', $staff);
        $config = StaffRecordKinds::get($kind);

        $query = $config['model']::query()->where('staff_id', $staff->id);
        foreach ($config['order'] as [$column, $direction]) {
            // Columns come from StaffRecordKinds, never the request. Empty dates sort last.
            $query->orderByRaw($column.' '.$direction.' nulls last');
        }

        return ApiResponse::success($query->get()->map(fn (Model $m) => $this->present($m))->values());
    }

    public function store(Request $request, Staff $staff, string $kind): JsonResponse
    {
        $this->authorize('update', $staff);
        $config = StaffRecordKinds::get($kind);

        $record = $config['model']::query()->create(['staff_id' => $staff->id, ...$this->validated($request, $config['rules'])]);

        return ApiResponse::created($this->present($record));
    }

    public function update(Request $request, Staff $staff, string $kind, int $record): JsonResponse
    {
        $this->authorize('update', $staff);
        $config = StaffRecordKinds::get($kind);

        $row = $config['model']::query()->where('staff_id', $staff->id)->findOrFail($record);
        $row->update($this->validated($request, $config['rules']));

        return ApiResponse::success($this->present($row));
    }

    public function destroy(Staff $staff, string $kind, int $record): JsonResponse
    {
        $this->authorize('update', $staff);
        $config = StaffRecordKinds::get($kind);

        $config['model']::query()->where('staff_id', $staff->id)->findOrFail($record)->delete();

        return ApiResponse::success(null);
    }

    /**
     * Only the kind's own fields; blank text becomes null.
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function validated(Request $request, array $rules): array
    {
        $data = $request->validate($rules);
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = trim($value) === '' ? null : trim($value);
            }
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function present(Model $m): array
    {
        $out = ['id' => $m->getKey()];
        foreach ($m->getFillable() as $field) {
            $value = $m->getAttribute($field);
            $out[$field] = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
        }

        return $out;
    }
}
