<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\CurrencyRate;
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
        return [
            'effective_date' => ['required', 'date', Rule::unique('tenant.currency_rates', 'effective_date')],
            'khr_per_usd' => ['required', 'numeric', 'min:0.0001'],
        ];
    }
}
