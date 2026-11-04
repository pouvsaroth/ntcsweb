<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreCompanyRequest;
use App\Http\Requests\Api\V1\Admin\UpdateCompanyRequest;
use App\Http\Resources\CompanyResource;
use App\Http\Responses\ApiResponse;
use App\Models\Company;
use App\Models\Invoice;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Billing > Companies: who pays for a company class. */
final class CompanyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Company::class);

        $companies = ApiQuery::for(Company::query()->withCount('classes'), $request)
            ->searchable('name', 'contact_person', 'email', 'phone', 'tax_number')
            ->filterable(['is_active'])
            ->sortable(['name', 'created_at'], default: 'name')
            ->paginate();

        return ApiResponse::success(CompanyResource::collection($companies));
    }

    public function store(StoreCompanyRequest $request): JsonResponse
    {
        $company = Company::query()->create($request->validated());

        return ApiResponse::created(new CompanyResource($company));
    }

    public function show(Company $company): JsonResponse
    {
        $this->authorize('view', $company);

        return ApiResponse::success(new CompanyResource($company->loadCount('classes')));
    }

    public function update(UpdateCompanyRequest $request, Company $company): JsonResponse
    {
        $company->update($request->validated());

        return ApiResponse::success(new CompanyResource($company));
    }

    public function destroy(Company $company): JsonResponse
    {
        $this->authorize('delete', $company);

        // Deleted classes and its invoices still point at the company.
        if ($company->classes()->withTrashed()->exists() || Invoice::query()->where('company_id', $company->getKey())->exists()) {
            return ApiResponse::error(__('This company has classes and cannot be deleted. Deactivate it instead.'), 422);
        }

        $company->delete();

        return ApiResponse::noContent();
    }
}
