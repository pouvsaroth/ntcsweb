<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccountingSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $tenant = app(TenantContext::class)->getOrFail();

        return $this->user()?->can('update', $tenant) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->idOrFail();
        $inTenant = Rule::exists('accounts', 'id')->where('tenant_id', $tenantId);

        return [
            'default_cash_account_id' => ['sometimes', 'required', $inTenant],
            'default_revenue_account_id' => ['sometimes', 'required', $inTenant],
            'default_expense_payment_account_id' => ['sometimes', 'required', $inTenant],
            'payment_method_accounts' => ['sometimes', 'array'],
            'payment_method_accounts.*' => [$inTenant],
            'expense_prefix' => ['sometimes', 'required', 'string', 'max:20', 'regex:/^[A-Z0-9]+$/'],
            'transaction_prefix' => ['sometimes', 'required', 'string', 'max:20', 'regex:/^[A-Z0-9]+$/'],
            'transfer_prefix' => ['sometimes', 'required', 'string', 'max:20', 'regex:/^[A-Z0-9]+$/'],
        ];
    }
}
