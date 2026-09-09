<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Support\Accounting\AccountType;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A manual "other income" entry (spec section 6) — a sale that never went through Invoice/Payment (e.g. a walk-in cash sale). */
class StoreIncomeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(Permissions::INCOME_CREATE) ?? false;
    }

    public function rules(): array
    {
        return [
            'revenue_account_id' => ['required', Rule::exists('tenant.accounts', 'id')->where('type', AccountType::REVENUE)->where('is_active', true)],
            'cash_account_id' => ['required', Rule::exists('tenant.accounts', 'id')->where('is_bank_or_cash', true)->where('is_active', true)],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999999.99'],
            'date' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }
}
