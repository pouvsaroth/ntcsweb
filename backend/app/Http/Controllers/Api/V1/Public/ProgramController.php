<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProgramResource;
use App\Http\Responses\ApiResponse;
use App\Models\Program;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The public course catalog. Unauthenticated — gated only by
 * `tenant.required` on the route group, same as every other public
 * endpoint. `?featured=1` is what the homepage's "Popular Programs" section
 * uses; the full `/programs` page omits it to show everything active.
 */
final class ProgramController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Program::query()->active()->ordered();

        if ($request->boolean('featured')) {
            $query->featured();
        }

        return ApiResponse::success(ProgramResource::collection($query->get()));
    }
}
