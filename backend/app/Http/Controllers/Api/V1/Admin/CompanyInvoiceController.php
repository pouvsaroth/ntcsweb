<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreCompanyInvoiceRequest;
use App\Http\Resources\InvoiceResource;
use App\Http\Responses\ApiResponse;
use App\Services\Billing\CompanyInvoiceService;
use Illuminate\Http\JsonResponse;

/** Billing > Invoices > Invoice by class: one invoice to a company for its class. */
final class CompanyInvoiceController extends Controller
{
    public function __construct(private readonly CompanyInvoiceService $companyInvoices) {}

    public function store(StoreCompanyInvoiceRequest $request): JsonResponse
    {
        $invoice = $this->companyInvoices->create($request->validated(), $request->user());

        return ApiResponse::created(new InvoiceResource($invoice));
    }
}
