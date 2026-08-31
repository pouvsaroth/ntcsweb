<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Public;

use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Unauthenticated by design — a visitor submitting this has no account yet.
 * `tenant.required` on the route group is the only gate.
 */
class StoreEnrollmentInquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->idOrFail();

        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            // Must be one of this school's own programs — a visitor on
            // School A's site must never be able to reference School B's.
            'program_id' => ['nullable', Rule::exists('programs', 'id')->where('tenant_id', $tenantId)],
            'message' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
