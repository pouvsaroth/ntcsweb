<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Meeting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Create/update a Front Office meeting; the permission check is the controller's authorize(). */
class MeetingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        $staff = Rule::exists('tenant.staff', 'id')->whereNull('deleted_at');

        return [
            'title' => [$required, 'string', 'max:200'],
            'meeting_date' => [$required, 'date_format:Y-m-d'],
            'start_time' => [$required, 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i', 'after:start_time'],
            'location' => ['nullable', 'string', 'max:200'],
            'agenda' => ['nullable', 'string', 'max:10000'],
            'minutes' => ['nullable', 'string', 'max:20000'],
            'status' => ['sometimes', Rule::in(Meeting::STATUSES)],
            'organizer_staff_id' => ['nullable', 'integer', $staff],
            'guests' => ['nullable', 'string', 'max:2000'],
            'participant_ids' => ['sometimes', 'array', 'max:200'],
            'participant_ids.*' => ['integer', 'distinct', $staff],
        ];
    }
}
