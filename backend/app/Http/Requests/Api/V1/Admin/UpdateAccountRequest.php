<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Account;
use App\Support\Accounting\AccountType;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Account $account */
        $account = $this->route('account');

        return $this->user()?->can('update', $account) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->idOrFail();
        /** @var Account $account */
        $account = $this->route('account');

        return [
            'code' => ['sometimes', 'required', 'string', 'max:20', Rule::unique('accounts')->where('tenant_id', $tenantId)->ignore($account)],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'type' => ['sometimes', 'required', Rule::in(AccountType::all())],
            'parent_id' => ['nullable', Rule::exists('accounts', 'id')->where('tenant_id', $tenantId), 'not_in:'.$account->getKey()],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_bank_or_cash' => ['sometimes', 'boolean'],
            // Deliberately no `is_active` here — activating/deactivating is
            // its own action with its own permission (accounts.deactivate),
            // not folded into a general-purpose field update. See
            // AccountController::deactivate()/reactivate().
        ];
    }
}
