<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\ResignationRequest;
use Illuminate\Foundation\Http\FormRequest;

class RejectResignationRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var ResignationRequest $resignationRequest */
        $resignationRequest = $this->route('resignation_request');

        return $this->user()?->can('reject', $resignationRequest) ?? false;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
