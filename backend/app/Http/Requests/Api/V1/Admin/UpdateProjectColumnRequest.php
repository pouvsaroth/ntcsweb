<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\ProjectColumn;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProjectColumnRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var ProjectColumn|null $column */
        $column = $this->route('project_column');

        return $column !== null && ($this->user()?->can('update', $column->project) ?? false);
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'color' => ['nullable', 'string', 'max:20'],
        ];
    }
}
