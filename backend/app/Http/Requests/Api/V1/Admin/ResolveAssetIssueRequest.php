<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\AssetIssue;
use Illuminate\Foundation\Http\FormRequest;

class ResolveAssetIssueRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var AssetIssue $issue */
        $issue = $this->route('asset_issue');

        return $this->user()?->can('resolve', $issue) ?? false;
    }

    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
