<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\FundProviderResource;
use App\Http\Responses\ApiResponse;
use App\Models\FundProvider;
use App\Models\FundTransaction;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Fund > Providers: the people who give money to the funds, with what each has given. */
final class FundProviderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', FundProvider::class);

        $given = fn (string $currency) => FundTransaction::query()
            ->selectRaw('COALESCE(SUM(fund_transactions.amount), 0)')
            ->join('funds', 'funds.id', '=', 'fund_transactions.fund_id')
            ->whereColumn('fund_transactions.fund_provider_id', 'fund_providers.id')
            ->where('fund_transactions.type', FundTransaction::IN)
            ->where('funds.currency', $currency);

        $query = FundProvider::query()->select('fund_providers.*')->selectSub($given('USD'), 'given_usd')->selectSub($given('KHR'), 'given_khr');

        $providers = ApiQuery::for($query, $request)
            ->searchable('name', 'code', 'phone')
            ->filterable(['is_active'])
            ->sortable(['name', 'code', 'created_at'], default: 'name')
            ->paginate();

        return ApiResponse::success(FundProviderResource::collection($providers));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', FundProvider::class);

        $provider = FundProvider::query()->create($request->validate($this->rules(required: true)));

        return ApiResponse::created(new FundProviderResource($provider->refresh()));
    }

    public function show(FundProvider $fundProvider): JsonResponse
    {
        $this->authorize('view', $fundProvider);

        return ApiResponse::success(new FundProviderResource($fundProvider));
    }

    public function update(Request $request, FundProvider $fundProvider): JsonResponse
    {
        $this->authorize('update', $fundProvider);

        $fundProvider->update($request->validate($this->rules(required: false)));

        return ApiResponse::success(new FundProviderResource($fundProvider));
    }

    /** A provider who has given money cannot be deleted; mark them inactive instead. */
    public function destroy(FundProvider $fundProvider): JsonResponse
    {
        $this->authorize('delete', $fundProvider);

        if ($fundProvider->entries()->exists()) {
            throw ValidationException::withMessages(['name' => __('This provider has given money to a fund, so they cannot be deleted. Mark them inactive instead.')]);
        }
        $fundProvider->delete();

        return ApiResponse::noContent();
    }

    /** @return array<string, list<mixed>> */
    private function rules(bool $required): array
    {
        $sometimes = $required ? [] : ['sometimes'];

        return [
            'name' => [...$sometimes, 'required', 'string', 'max:255'],
            'gender' => ['nullable', 'string', 'max:10'],
            'phone' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
