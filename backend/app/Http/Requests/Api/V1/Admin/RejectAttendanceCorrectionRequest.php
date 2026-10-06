<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\AttendanceCorrection;
use App\Services\Approvals\ApprovalFlow;
use Illuminate\Foundation\Http\FormRequest;

class RejectAttendanceCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var AttendanceCorrection $correction */
        $correction = $this->route('attendance_correction');

        // An approval-flow item: the current step's group; otherwise the reject permission.
        return $this->user() !== null && app(ApprovalFlow::class)->mayDecide($correction, $this->user(), 'reject');
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:500']];
    }
}
