<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\ExpenseEntity;
use Illuminate\Foundation\Http\FormRequest;

class UpdateExpenseEntityRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var ExpenseEntity $entity */
        $entity = $this->route('expense_entity');

        return $this->user()?->can('update', $entity) ?? false;
    }

    public function rules(): array
    {
        return StoreExpenseEntityRequest::fields(required: false);
    }
}
