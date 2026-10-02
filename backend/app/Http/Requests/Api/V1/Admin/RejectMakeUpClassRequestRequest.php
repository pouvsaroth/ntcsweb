<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\MakeUpClassRequest;
use App\Services\Approvals\ApprovalFlow;
use Illuminate\Foundation\Http\FormRequest;

class RejectMakeUpClassRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var MakeUpClassRequest $makeUpClassRequest */
        $makeUpClassRequest = $this->route('make_up_class_request');

        // An approval-flow item: the current step's group; otherwise the reject permission.
        return $this->user() !== null && app(ApprovalFlow::class)->mayDecide($makeUpClassRequest, $this->user(), 'reject');
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
