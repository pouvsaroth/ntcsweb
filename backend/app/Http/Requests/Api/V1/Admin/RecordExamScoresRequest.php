<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\ExamScore;
use Illuminate\Foundation\Http\FormRequest;

/**
 * One save = any number of rows from the Grades tab. Whether each
 * application is approved and inside the user's own classes is checked in
 * ExamScoreService::record(), against the same query the list uses.
 */
class RecordExamScoresRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('record', ExamScore::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'entries' => ['required', 'array', 'min:1', 'max:500'],
            'entries.*.exam_application_id' => ['required', 'integer', 'distinct'],
            'entries.*.score' => ['present', 'nullable', 'numeric', 'min:0', 'max:100'],
            'entries.*.remark' => ['nullable', 'string', 'max:500'],
        ];
    }
}
