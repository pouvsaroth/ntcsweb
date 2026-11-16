<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\SaveStaffCardDesignRequest;
use App\Http\Resources\StaffCardDesignResource;
use App\Http\Responses\ApiResponse;
use App\Models\StaffCardDesign;
use App\Support\Query\ApiQuery;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** HRM > Staff cards: the school's card designs. */
final class StaffCardDesignController extends Controller
{
    /** Uploaded photo inputs => their column. */
    private const FILES = [
        'front_background' => 'front_background_path',
        'back_background' => 'back_background_path',
        'signature' => 'signature_path',
    ];

    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StaffCardDesign::class);

        $designs = ApiQuery::for(StaffCardDesign::query(), $request)
            ->searchable('name')
            ->sortable(['name', 'updated_at', 'created_at'])
            ->paginate();

        return ApiResponse::success(StaffCardDesignResource::collection($designs));
    }

    public function show(StaffCardDesign $design): JsonResponse
    {
        $this->authorize('view', $design);

        return ApiResponse::success(new StaffCardDesignResource($design));
    }

    public function store(SaveStaffCardDesignRequest $request): JsonResponse
    {
        $design = new StaffCardDesign([
            ...$request->safe()->only(['name', 'width_mm', 'height_mm']),
            'front_fields' => $request->fields('front_fields') ?? [],
            'back_fields' => $request->fields('back_fields') ?? [],
            'created_by' => $request->user()->getKey(),
        ]);
        $this->applyFiles($request, $design);
        $design->save();

        return ApiResponse::created(new StaffCardDesignResource($design));
    }

    public function update(SaveStaffCardDesignRequest $request, StaffCardDesign $design): JsonResponse
    {
        $design->fill($request->safe()->only(['name', 'width_mm', 'height_mm']));
        foreach (['front_fields', 'back_fields'] as $side) {
            $fields = $request->fields($side);
            if ($fields !== null) {
                $design->{$side} = $fields;
            }
        }
        $old = $this->applyFiles($request, $design);
        $design->save();
        $this->deleteFiles($old);

        return ApiResponse::success(new StaffCardDesignResource($design));
    }

    public function destroy(StaffCardDesign $design): JsonResponse
    {
        $this->authorize('delete', $design);

        $paths = array_map(fn (string $column) => $design->{$column}, array_values(self::FILES));
        $design->delete();
        $this->deleteFiles($paths);

        return ApiResponse::noContent();
    }

    /**
     * Store new uploads and clear removed photos on the model (not saved yet).
     *
     * @return list<string|null> the replaced paths, to delete once saved
     */
    private function applyFiles(SaveStaffCardDesignRequest $request, StaffCardDesign $design): array
    {
        $old = [];
        foreach (self::FILES as $input => $column) {
            if ($request->hasFile($input)) {
                $old[] = $design->{$column};
                $path = $request->file($input)->store($this->context->getOrFail()->storagePath('staff-cards'), 'public');
                abort_if($path === false, 500, 'Failed to store the uploaded photo.');
                $design->{$column} = $path;
            } elseif ($request->boolean("remove_{$input}")) {
                $old[] = $design->{$column};
                $design->{$column} = null;
            }
        }

        return $old;
    }

    /** @param  list<string|null>  $paths */
    private function deleteFiles(array $paths): void
    {
        foreach (array_filter($paths) as $path) {
            Storage::disk('public')->delete($path);
        }
    }
}
