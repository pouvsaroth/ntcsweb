<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\ExpenseSupport;
use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Add / edit a Support record: money needs an amount and currency; food needs what was brought (an amount is optional). */
class SaveExpenseSupportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $support = $this->route('expense_support');

        return $support instanceof ExpenseSupport
            ? ($this->user()?->can('update', $support) ?? false)
            : ($this->user()?->can('create', ExpenseSupport::class) ?? false);
    }

    public function rules(): array
    {
        $money = $this->input('kind') === ExpenseSupport::MONEY;

        return [
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:50'],
            'relationship' => ['nullable', Rule::in(ExpenseSupport::RELATIONSHIPS)],
            'support_date' => ['required', 'date'],
            'kind' => ['required', Rule::in(ExpenseSupport::KINDS)],
            'amount' => [$money ? 'required' : 'nullable', 'numeric', $money ? 'min:0.01' : 'min:0', 'max:999999999.99'],
            'currency' => [$money || $this->filled('amount') ? 'required' : 'nullable', Rule::in([Tenant::CURRENCY_USD, Tenant::CURRENCY_KHR])],
            'items' => [$money ? 'nullable' : 'required', 'string', 'max:500'],
            'expense_entity_id' => ['required', 'integer', Rule::exists('tenant.expense_entities', 'id')],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
