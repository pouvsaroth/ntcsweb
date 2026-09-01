<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreAccountRequest;
use App\Http\Requests\Api\V1\Admin\UpdateAccountRequest;
use App\Http\Resources\AccountResource;
use App\Http\Responses\ApiResponse;
use App\Models\Account;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AccountController extends Controller
{
    /** A Chart of Accounts is small — every picker/tree view just passes `?per_page=200` to get it all at once, same convention as classesService.listAll() on the frontend. */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Account::class);

        $accounts = ApiQuery::for(Account::query()->with('parent'), $request)
            ->searchable('code', 'name')
            ->filterable(['type', 'is_active'])
            ->sortable(['code', 'name', 'created_at'], default: 'code')
            ->paginate();

        return ApiResponse::success(AccountResource::collection($accounts));
    }

    public function store(StoreAccountRequest $request): JsonResponse
    {
        $account = Account::query()->create($request->validated());

        return ApiResponse::created(new AccountResource($account->load('parent')));
    }

    public function show(Account $account): JsonResponse
    {
        $this->authorize('view', $account);

        return ApiResponse::success(new AccountResource($account->load('parent')));
    }

    public function update(UpdateAccountRequest $request, Account $account): JsonResponse
    {
        $account->update($request->validated());

        return ApiResponse::success(new AccountResource($account->load('parent')));
    }

    public function deactivate(Account $account): JsonResponse
    {
        $this->authorize('deactivate', $account);

        $account->update(['is_active' => false]);

        return ApiResponse::success(new AccountResource($account));
    }

    public function reactivate(Account $account): JsonResponse
    {
        $this->authorize('deactivate', $account);

        $account->update(['is_active' => true]);

        return ApiResponse::success(new AccountResource($account));
    }
}
