<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\InterviewEvaluation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An interviewer's evaluation: a 1–5 score for every criterion, a
 * recommendation, and optional notes. Who may send it is decided in
 * InterviewEvaluationController::store().
 */
class InterviewEvaluationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'interview_id' => ['required', 'integer', Rule::exists('tenant.interviews', 'id')],
            'scores' => ['required', 'array'],
            'recommendation' => ['required', Rule::in(InterviewEvaluation::RECOMMENDATIONS)],
            'strengths' => ['nullable', 'string', 'max:2000'],
            'concerns' => ['nullable', 'string', 'max:2000'],
        ];

        foreach (InterviewEvaluation::CRITERIA as $criterion) {
            $rules["scores.{$criterion}"] = ['required', 'integer', 'min:1', 'max:5'];
        }

        return $rules;
    }
}
