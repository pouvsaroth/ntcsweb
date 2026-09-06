<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCurrencyRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $rate = $this->route('currency_rate');

        return $this->user()?->can('update', $rate) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->idOrFail();
        $rate = $this->route('currency_rate');

        return [
            'effective_date' => [
                'sometimes', 'required', 'date',
                Rule::unique('currency_rates')->where('tenant_id', $tenantId)->ignore($rate),
            ],
            'khr_per_usd' => ['sometimes', 'required', 'numeric', 'min:0.0001'],
        ];
    }
}
