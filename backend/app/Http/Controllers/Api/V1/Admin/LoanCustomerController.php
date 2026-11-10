<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\LoanCustomerResource;
use App\Http\Responses\ApiResponse;
use App\Models\LoanCustomer;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Loan > Customers: the people who keep savings with the owner and borrow from them. */
final class LoanCustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', LoanCustomer::class);

        $customers = ApiQuery::for(LoanCustomer::query(), $request)
            ->searchable('name', 'code', 'phone', 'id_card_no')
            ->filterable(['is_active', 'gender'])
            ->sortable(['name', 'code', 'created_at'], default: 'name')
            ->paginate();

        return ApiResponse::success(LoanCustomerResource::collection($customers));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', LoanCustomer::class);

        $customer = LoanCustomer::query()->create($request->validate($this->rules(required: true)));

        return ApiResponse::created(new LoanCustomerResource($customer->refresh()));
    }

    public function show(LoanCustomer $loanCustomer): JsonResponse
    {
        $this->authorize('view', $loanCustomer);

        return ApiResponse::success(new LoanCustomerResource($loanCustomer));
    }

    public function update(Request $request, LoanCustomer $loanCustomer): JsonResponse
    {
        $this->authorize('update', $loanCustomer);

        $loanCustomer->update($request->validate($this->rules(required: false)));

        return ApiResponse::success(new LoanCustomerResource($loanCustomer));
    }

    /** Savings accounts and loans (steps 2–3) will block this; deactivate instead. */
    public function destroy(LoanCustomer $loanCustomer): JsonResponse
    {
        $this->authorize('delete', $loanCustomer);

        $loanCustomer->delete();

        return ApiResponse::noContent();
    }

    /** @return array<string, list<mixed>> */
    private function rules(bool $required): array
    {
        $sometimes = $required ? [] : ['sometimes'];

        return [
            'name' => [...$sometimes, 'required', 'string', 'max:255'],
            'gender' => ['nullable', Rule::in(LoanCustomer::GENDERS)],
            'phone' => ['nullable', 'string', 'max:32'],
            'id_card_no' => ['nullable', 'string', 'max:50'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'address' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
