<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\BulkStoreClassroomTablesRequest;
use App\Http\Requests\Api\V1\Admin\StoreClassroomTableRequest;
use App\Http\Requests\Api\V1\Admin\UpdateClassroomTableRequest;
use App\Http\Resources\ClassroomTableResource;
use App\Http\Responses\ApiResponse;
use App\Models\ClassroomTable;
use App\Models\Enrollment;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ClassroomTableController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ClassroomTable::class);

        $tables = ApiQuery::for(ClassroomTable::query()->with('classroom'), $request)
            ->filterable(['classroom_id'])
            ->sortable(['sort_order', 'name', 'created_at'], default: 'sort_order')
            ->paginate();

        return ApiResponse::success(ClassroomTableResource::collection($tables));
    }

    public function store(StoreClassroomTableRequest $request): JsonResponse
    {
        $table = ClassroomTable::query()->create($request->validated());

        return ApiResponse::created(new ClassroomTableResource($table->load('classroom')));
    }

    /**
     * Many tables at once: a numbered range (prefix + 01..N, order = number)
     * or a copy of another room's tables. A name the room already has is
     * skipped; one deleted earlier comes back.
     */
    public function bulkStore(BulkStoreClassroomTablesRequest $request): JsonResponse
    {
        $data = $request->validated();
        $classroomId = (int) $data['classroom_id'];

        if (! empty($data['copy_from_classroom_id'])) {
            $rows = ClassroomTable::query()
                ->where('classroom_id', $data['copy_from_classroom_id'])
                ->orderBy('sort_order')->orderBy('name')
                ->get(['name', 'sort_order', 'allow_many_students', 'remark'])
                ->map(fn (ClassroomTable $t) => ['name' => $t->name, 'sort_order' => $t->sort_order, 'allow_many_students' => $t->allow_many_students, 'remark' => $t->remark])
                ->all();
        } else {
            $start = (int) ($data['start_number'] ?? 1);
            $end = $start + (int) $data['count'] - 1;
            $width = max(2, strlen((string) $end));
            $prefix = (string) ($data['prefix'] ?? '');
            $rows = [];
            for ($n = $start; $n <= $end; $n++) {
                $rows[] = [
                    'name' => $prefix.str_pad((string) $n, $width, '0', STR_PAD_LEFT),
                    'sort_order' => $n,
                    'allow_many_students' => (bool) ($data['allow_many_students'] ?? false),
                    'remark' => ($data['remark'] ?? null) ?: null,
                ];
            }
        }

        $created = 0;
        $skipped = 0;
        DB::connection('tenant')->transaction(function () use ($rows, $classroomId, &$created, &$skipped) {
            $existing = ClassroomTable::withTrashed()->where('classroom_id', $classroomId)->get()->keyBy('name');
            foreach ($rows as $row) {
                $table = $existing->get($row['name']);
                if ($table === null) {
                    ClassroomTable::query()->create($row + ['classroom_id' => $classroomId]);
                    $created++;
                } elseif ($table->trashed()) {
                    $table->restore();
                    $table->update($row);
                    $created++;
                } else {
                    $skipped++;
                }
            }
        });

        return ApiResponse::created(['created' => $created, 'skipped' => $skipped]);
    }

    public function show(ClassroomTable $classroomTable): JsonResponse
    {
        $this->authorize('view', $classroomTable);

        return ApiResponse::success(new ClassroomTableResource($classroomTable->load('classroom')));
    }

    public function update(UpdateClassroomTableRequest $request, ClassroomTable $classroomTable): JsonResponse
    {
        $classroomTable->update($request->validated());

        return ApiResponse::success(new ClassroomTableResource($classroomTable->load('classroom')));
    }

    public function destroy(ClassroomTable $classroomTable): JsonResponse
    {
        $this->authorize('delete', $classroomTable);

        if ($classroomTable->enrollments()->where('status', '!=', Enrollment::STATUS_DROPPED)->exists()) {
            return ApiResponse::error('This table has a student seated at it and cannot be deleted.', 422);
        }

        $classroomTable->delete();

        return ApiResponse::noContent();
    }
}
