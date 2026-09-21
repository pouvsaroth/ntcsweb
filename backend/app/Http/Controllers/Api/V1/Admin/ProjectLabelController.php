<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreProjectLabelRequest;
use App\Http\Resources\ProjectLabelResource;
use App\Http\Responses\ApiResponse;
use App\Models\ProjectLabel;
use App\Support\Authorization\Permissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;

final class ProjectLabelController extends Controller
{
    /** Reading the label catalog needs no permission — same reasoning as Base Data lookups (Permissions::BASE_DATA_VIEW's docblock): anyone filling in a card form needs to see the available labels. */
    public function index(): JsonResponse
    {
        $labels = ProjectLabel::query()->orderBy('name')->get();

        return ApiResponse::success(ProjectLabelResource::collection($labels));
    }

    public function store(StoreProjectLabelRequest $request): JsonResponse
    {
        $label = ProjectLabel::query()->create($request->validated());

        return ApiResponse::created(new ProjectLabelResource($label));
    }

    /** No ProjectLabelPolicy exists — a label isn't scoped to a project, so this checks the permission directly, same as StoreProjectLabelRequest. */
    public function destroy(ProjectLabel $projectLabel): JsonResponse
    {
        if (! request()->user()?->hasPermission(Permissions::PROJECTS_UPDATE)) {
            throw new AuthorizationException;
        }

        $projectLabel->delete();

        return ApiResponse::noContent();
    }
}
