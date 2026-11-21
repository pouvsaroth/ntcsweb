<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\LeaveRequest;
use Illuminate\Foundation\Http\FormRequest;

/** Taking back an approved student permission — see LeaveRequestService::cancel(). */
class CancelLeaveRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var LeaveRequest $leaveRequest */
        $leaveRequest = $this->route('leave_request');

        return $this->user()?->can('cancel', $leaveRequest) ?? false;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
