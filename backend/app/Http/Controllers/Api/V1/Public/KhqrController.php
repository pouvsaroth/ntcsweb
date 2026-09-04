<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Support\Billing\Khqr;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Turns the school's static KHQR template (set under School Settings) into a
 * fixed-amount code for one specific amount — used by the registration
 * wizard's payment step, before any Student/Invoice exists yet, so this
 * takes a bare amount+currency rather than an invoice id.
 */
final class KhqrController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function preview(Request $request): JsonResponse
    {
        $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['required', Rule::in(['USD', 'KHR'])],
        ]);

        $template = $this->context->getOrFail()->khqrTemplate();

        if ($template === null) {
            return ApiResponse::error('This school has not set up QR payment yet.', 404, 'KHQR_NOT_CONFIGURED');
        }

        try {
            $khqr = Khqr::withAmount($template, (float) $request->input('amount'), $request->string('currency')->toString());
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error('QR payment is misconfigured for this school. Please pay by cash instead.', 500, 'KHQR_INVALID_TEMPLATE');
        }

        return ApiResponse::success(['khqr_string' => $khqr]);
    }
}
