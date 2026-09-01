<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Support\Authorization\Permissions;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The manual correction path from spec section 28 — a posted transaction is
 * never edited, only corrected via a fresh, explicit debit/credit posting.
 * Requires `accounting.adjustment.create`, a tighter permission than the
 * general `transactions.create` a transfer needs.
 */
class StoreAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(Permissions::ACCOUNTING_ADJUSTMENT_CREATE) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->idOrFail();
        $inTenant = Rule::exists('accounts', 'id')->where('tenant_id', $tenantId)->where('is_active', true);

        return [
            'debit_account_id' => ['required', 'different:credit_account_id', $inTenant],
            'credit_account_id' => ['required', $inTenant],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999999.99'],
            'date' => ['nullable', 'date'],
            'description' => ['required', 'string', 'max:500'],
        ];
    }
}
