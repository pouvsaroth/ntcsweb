<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Expense;
use Illuminate\Foundation\Http\FormRequest;

/** No body required — self-approval is blocked in ExpenseService, not by any input here. */
class ApproveExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Expense $expense */
        $expense = $this->route('expense');

        return $this->user()?->can('approve', $expense) ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}
