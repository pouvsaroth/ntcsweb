<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreProgramRequest;
use App\Http\Requests\Api\V1\Admin\UpdateProgramRequest;
use App\Http\Resources\ProgramResource;
use App\Http\Responses\ApiResponse;
use App\Models\Program;
use App\Support\Query\ApiQuery;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

final class ProgramController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Program::class);

        $programs = ApiQuery::for(Program::query(), $request)
            ->searchable('title', 'subtitle', 'category')
            ->filterable(['status', 'level'])
            ->sortable(['title', 'sort_order', 'created_at'], default: 'sort_order')
            ->paginate();

        return ApiResponse::success(ProgramResource::collection($programs));
    }

    public function store(StoreProgramRequest $request): JsonResponse
    {
        $program = Program::query()->create([
            ...$request->safe()->except('image'),
            'image_path' => $request->hasFile('image') ? $this->storeImage($request) : null,
        ]);

        return ApiResponse::created(new ProgramResource($program));
    }

    public function show(Program $program): JsonResponse
    {
        $this->authorize('view', $program);

        return ApiResponse::success(new ProgramResource($program));
    }

    public function update(UpdateProgramRequest $request, Program $program): JsonResponse
    {
        $previousImagePath = $program->image_path;
        $newImagePath = $request->hasFile('image') ? $this->storeImage($request) : null;

        $program->update([
            ...$request->safe()->except('image'),
            ...($newImagePath !== null ? ['image_path' => $newImagePath] : []),
        ]);

        // Only removed once the new image is safely persisted — see
        // HomeSlideController::update() for why this ordering matters.
        if ($newImagePath !== null && $previousImagePath !== null) {
            Storage::disk('public')->delete($previousImagePath);
        }

        return ApiResponse::success(new ProgramResource($program));
    }

    public function destroy(Program $program): JsonResponse
    {
        $this->authorize('delete', $program);

        // Soft-deleted only — Program::booted() removes the image itself on
        // a *force* delete, so a mistaken removal stays recoverable.
        $program->delete();

        return ApiResponse::noContent();
    }

    private function storeImage(StoreProgramRequest|UpdateProgramRequest $request): string
    {
        $tenant = $this->context->getOrFail();

        $path = $request->file('image')->store($tenant->storagePath('programs'), 'public');

        if ($path === false) {
            abort(500, 'Failed to store the uploaded image.');
        }

        return $path;
    }
}
