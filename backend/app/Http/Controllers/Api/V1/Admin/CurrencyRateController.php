<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreCurrencyRateRequest;
use App\Http\Requests\Api\V1\Admin\UpdateCurrencyRateRequest;
use App\Http\Resources\CurrencyRateResource;
use App\Http\Responses\ApiResponse;
use App\Models\CurrencyRate;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class CurrencyRateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CurrencyRate::class);

        $rates = ApiQuery::for(CurrencyRate::query()->with('creator'), $request)
            ->sortable(['effective_date', 'created_at'], default: '-effective_date')
            ->paginate();

        return ApiResponse::success(CurrencyRateResource::collection($rates));
    }

    public function store(StoreCurrencyRateRequest $request): JsonResponse
    {
        $rate = CurrencyRate::query()->create([
            ...$request->validated(),
            'created_by' => Auth::id(),
        ]);

        return ApiResponse::created(new CurrencyRateResource($rate->load('creator')));
    }

    public function show(CurrencyRate $currencyRate): JsonResponse
    {
        $this->authorize('view', $currencyRate);

        return ApiResponse::success(new CurrencyRateResource($currencyRate->load('creator')));
    }

    public function update(UpdateCurrencyRateRequest $request, CurrencyRate $currencyRate): JsonResponse
    {
        $currencyRate->update($request->validated());

        return ApiResponse::success(new CurrencyRateResource($currencyRate->load('creator')));
    }

    public function destroy(CurrencyRate $currencyRate): JsonResponse
    {
        $this->authorize('delete', $currencyRate);

        $currencyRate->delete();

        return ApiResponse::noContent();
    }
}
