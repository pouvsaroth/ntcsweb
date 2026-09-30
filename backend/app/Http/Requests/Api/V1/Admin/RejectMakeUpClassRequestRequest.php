<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\MakeUpClassRequest;
use Illuminate\Foundation\Http\FormRequest;

class RejectMakeUpClassRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var MakeUpClassRequest $makeUpClassRequest */
        $makeUpClassRequest = $this->route('make_up_class_request');

        return $this->user()?->can('reject', $makeUpClassRequest) ?? false;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
