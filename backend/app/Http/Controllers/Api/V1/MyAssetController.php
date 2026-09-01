<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AssetResource;
use App\Http\Responses\ApiResponse;
use App\Models\Asset;
use App\Models\Staff;
use App\Models\Student;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Self-service: "my assets," not "all assets." No `assets.view` permission
 * required — a Staff/Student/User account sees only what is currently
 * assigned to their own identity, the same identity-based rule AssetPolicy
 * ::view() applies to a direct `GET /assets/{id}` hit.
 */
final class MyAssetController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $identities = $this->ownIdentities($request);

        $query = Asset::query()->whereHas('assignments', function ($assignment) use ($identities) {
            $assignment->where('status', 'ACTIVE')->where(function ($q) use ($identities) {
                foreach ($identities as [$type, $id]) {
                    $q->orWhere(fn ($inner) => $inner->where('assignable_type', $type)->where('assignable_id', $id));
                }
            });
        })->with(['category', 'location', 'department']);

        $assets = ApiQuery::for($query, $request)
            ->sortable(['asset_number', 'name', 'created_at'], default: '-created_at')
            ->paginate();

        return ApiResponse::success(AssetResource::collection($assets));
    }

    /** @return list<array{0: class-string, 1: int}> */
    private function ownIdentities(Request $request): array
    {
        $user = $request->user();
        $identities = [[$user::class, $user->getKey()]];

        $staffId = Staff::query()->where('user_id', $user->getKey())->value('id');
        if ($staffId !== null) {
            $identities[] = [Staff::class, $staffId];
        }

        $studentId = $user->student?->id;
        if ($studentId !== null) {
            $identities[] = [Student::class, $studentId];
        }

        return $identities;
    }
}
