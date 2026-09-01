<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Expense;
use Illuminate\Foundation\Http\FormRequest;

class StoreExpenseAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Expense $expense */
        $expense = $this->route('expense');

        return $this->user()?->can('update', $expense) ?? false;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp'],
        ];
    }
}
