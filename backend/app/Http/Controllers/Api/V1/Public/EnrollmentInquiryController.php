<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Public\StoreEnrollmentInquiryRequest;
use App\Http\Responses\ApiResponse;
use App\Models\EnrollmentInquiry;
use Illuminate\Http\JsonResponse;

/**
 * The public "Register" form's submission endpoint — captures a visitor's
 * interest, not a real enrollment. See EnrollmentInquiry's docblock.
 */
final class EnrollmentInquiryController extends Controller
{
    public function store(StoreEnrollmentInquiryRequest $request): JsonResponse
    {
        EnrollmentInquiry::query()->create($request->validated());

        return ApiResponse::created(null, 'Thank you — we will contact you soon.');
    }
}
