<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Step two of the shared ERP domain's login, only reached when the identity
 * verified against more than one account — see
 * AuthController::loginAcrossTenants()/selectTenant(). The password is
 * deliberately not asked for again here; `selection_token` is what proves it
 * already checked out, and `tenant_id` just picks which of the accounts that
 * verified to actually sign in as.
 */
class SelectLoginTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'selection_token' => ['required', 'string'],
            'tenant_id' => ['required', 'integer'],
            'device_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }
}
