<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\ExamApplication;
use Illuminate\Foundation\Http\FormRequest;

class RejectExamApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var ExamApplication $examApplication */
        $examApplication = $this->route('exam_application');

        return $this->user()?->can('reject', $examApplication) ?? false;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
