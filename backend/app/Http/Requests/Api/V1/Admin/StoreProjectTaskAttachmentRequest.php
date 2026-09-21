<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\ProjectTask;
use Illuminate\Foundation\Http\FormRequest;

class StoreProjectTaskAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var ProjectTask|null $task */
        $task = $this->route('project_task');

        return $task !== null && ($this->user()?->can('update', $task->project) ?? false);
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx,zip'],
        ];
    }
}
