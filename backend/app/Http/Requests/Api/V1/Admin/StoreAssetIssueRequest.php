<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\AssetIssue;
use App\Support\Assets\IssuePriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAssetIssueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', AssetIssue::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'reported_date' => ['nullable', 'date'],
            'priority' => ['sometimes', Rule::in(IssuePriority::all())],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
