<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\CurrencyRate;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCurrencyRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', CurrencyRate::class) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->idOrFail();

        return [
            'effective_date' => ['required', 'date', Rule::unique('currency_rates')->where('tenant_id', $tenantId)],
            'khr_per_usd' => ['required', 'numeric', 'min:0.0001'],
        ];
    }
}
