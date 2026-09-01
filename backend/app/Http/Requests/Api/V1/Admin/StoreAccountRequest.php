<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Account;
use App\Support\Accounting\AccountType;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Account::class) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->idOrFail();

        return [
            'code' => ['required', 'string', 'max:20', Rule::unique('accounts')->where('tenant_id', $tenantId)],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(AccountType::all())],
            'parent_id' => ['nullable', Rule::exists('accounts', 'id')->where('tenant_id', $tenantId)],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_bank_or_cash' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
