<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\ExamApplication;
use App\Services\Approvals\ApprovalFlow;
use Illuminate\Foundation\Http\FormRequest;

class RejectExamApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var ExamApplication $examApplication */
        $examApplication = $this->route('exam_application');

        // An approval-flow item: the current step's group; otherwise the reject permission.
        return $this->user() !== null && app(ApprovalFlow::class)->mayDecide($examApplication, $this->user(), 'reject');
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
