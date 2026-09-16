<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Identity-gated, not permission-gated — any signed-in staff member may
 * submit a resignation request for themselves. See
 * MyResignationRequestController's docblock, same pattern as
 * StoreMyLeaveRequestRequest.
 */
class StoreMyResignationRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->staff !== null;
    }

    public function rules(): array
    {
        return [
            'resignation_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
