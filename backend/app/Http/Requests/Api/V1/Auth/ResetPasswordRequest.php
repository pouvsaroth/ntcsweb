<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'tenant' => ['sometimes', 'nullable', 'string', 'max:255'],

            // Password strength lives in AppServiceProvider::configurePasswords
            // so the same rule applies to resets, invitations and admin-set
            // passwords without being restated at each call site.
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }
}
