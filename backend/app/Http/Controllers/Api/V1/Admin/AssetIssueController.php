<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\ResolveAssetIssueRequest;
use App\Http\Requests\Api\V1\Admin\StoreAssetIssueRequest;
use App\Http\Requests\Api\V1\Admin\UpdateAssetIssueRequest;
use App\Http\Resources\AssetIssueResource;
use App\Http\Responses\ApiResponse;
use App\Models\Asset;
use App\Models\AssetIssue;
use App\Services\Assets\AssetIssueService;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AssetIssueController extends Controller
{
    public function __construct(private readonly AssetIssueService $issues) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AssetIssue::class);

        $issues = ApiQuery::for(AssetIssue::query()->with(['asset', 'reportedBy']), $request)
            ->searchable('issue_number', 'title')
            ->filterable(['status', 'priority', 'asset_id'])
            ->sortable(['reported_date', 'priority', 'created_at'], default: '-reported_date')
            ->paginate();

        return ApiResponse::success(AssetIssueResource::collection($issues));
    }

    public function store(StoreAssetIssueRequest $request, Asset $asset): JsonResponse
    {
        $issue = $this->issues->report($asset, $request->validated(), $request->user());

        return ApiResponse::created(new AssetIssueResource($issue->load(['asset', 'reportedBy'])));
    }

    public function show(AssetIssue $assetIssue): JsonResponse
    {
        $this->authorize('view', $assetIssue);

        return ApiResponse::success(new AssetIssueResource($assetIssue->load(['asset', 'reportedBy', 'resolvedBy', 'repairs'])));
    }

    public function update(UpdateAssetIssueRequest $request, AssetIssue $assetIssue): JsonResponse
    {
        $issue = $this->issues->update($assetIssue, $request->validated(), $request->user());

        return ApiResponse::success(new AssetIssueResource($issue));
    }

    public function resolve(ResolveAssetIssueRequest $request, AssetIssue $assetIssue): JsonResponse
    {
        $issue = $this->issues->resolve($assetIssue, $request->user(), $request->validated('notes'));

        return ApiResponse::success(new AssetIssueResource($issue));
    }
}
