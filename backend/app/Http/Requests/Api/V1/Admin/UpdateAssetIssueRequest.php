<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\AssetIssue;
use App\Support\Assets\IssuePriority;
use App\Support\Assets\IssueStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAssetIssueRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var AssetIssue $issue */
        $issue = $this->route('asset_issue');

        return $this->user()?->can('update', $issue) ?? false;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::in(IssueStatus::all())],
            'priority' => ['sometimes', Rule::in(IssuePriority::all())],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
