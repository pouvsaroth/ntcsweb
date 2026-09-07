<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\ApprovalRequest;
use Illuminate\Foundation\Http\FormRequest;

class RejectApprovalRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var ApprovalRequest $approvalRequest */
        $approvalRequest = $this->route('approval_request');

        return $this->user()?->can('reject', $approvalRequest) ?? false;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
