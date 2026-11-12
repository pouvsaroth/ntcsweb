<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\ExpenseEntity;
use Illuminate\Foundation\Http\FormRequest;

class StoreExpenseEntityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', ExpenseEntity::class) ?? false;
    }

    public function rules(): array
    {
        return self::fields(required: true);
    }

    /** Shared with UpdateExpenseEntityRequest. */
    public static function fields(bool $required): array
    {
        return [
            'name' => $required ? ['required', 'string', 'max:255'] : ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
            'is_school' => ['sometimes', 'boolean'],
        ];
    }
}
