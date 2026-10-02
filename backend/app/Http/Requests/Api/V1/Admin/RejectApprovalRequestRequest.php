<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\ApprovalRequest;
use App\Services\Approvals\ApprovalFlow;
use Illuminate\Foundation\Http\FormRequest;

class RejectApprovalRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var ApprovalRequest $approvalRequest */
        $approvalRequest = $this->route('approval_request');

        // An approval-flow item: the current step's group; otherwise the reject permission.
        return $this->user() !== null && app(ApprovalFlow::class)->mayDecide($approvalRequest, $this->user(), 'reject');
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
