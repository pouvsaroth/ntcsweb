<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\LeaveRequest;
use App\Models\Staff;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * HR files a leave request on a working staff member's behalf (HRM > Leave
 * Management > Leave request) — same fields as the staff member's own form,
 * plus who it's for. Authorized in LeaveRequestController::store().
 */
class StoreStaffLeaveRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'staff_id' => ['required', 'integer', Rule::exists('tenant.staff', 'id')->whereIn('status', Staff::STATUSES_WORKING)->whereNull('deleted_at')],
            'leave_type_id' => ['nullable', 'integer'],
            'day_part' => ['nullable', Rule::in(LeaveRequest::DAY_PARTS)],
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
            'reason' => ['required', 'string', 'max:1000'],
            'attachments' => ['sometimes', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp'],
        ];
    }
}
