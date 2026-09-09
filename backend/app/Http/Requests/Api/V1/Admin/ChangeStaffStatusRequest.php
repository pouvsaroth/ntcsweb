<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Staff;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeStaffStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Staff $staff */
        $staff = $this->route('staff');

        return $this->user()?->can('changeStatus', $staff) ?? false;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(Staff::STATUSES_MANAGEABLE)],
            'reason' => ['required', 'string', 'max:2000'],
            'effective_date' => ['required', 'date'],
            'requested_date' => ['required', 'date', 'before_or_equal:effective_date'],
        ];
    }
}
