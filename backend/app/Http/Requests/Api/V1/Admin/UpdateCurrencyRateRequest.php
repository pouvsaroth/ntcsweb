<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

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
        $rate = $this->route('currency_rate');

        return [
            'effective_date' => [
                'sometimes', 'required', 'date',
                Rule::unique('tenant.currency_rates', 'effective_date')->ignore($rate),
            ],
            'khr_per_usd' => ['sometimes', 'required', 'numeric', 'min:0.0001'],
        ];
    }
}
