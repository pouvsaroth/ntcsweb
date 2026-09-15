<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * An admin setting a new password for someone else's account — no
 * `current_password` check (unlike ChangePasswordRequest), since the acting
 * admin isn't proving they know the target's old one, they're overriding it.
 * See UserPolicy::resetPassword() for why this can never target the admin's
 * own account.
 */
class ResetUserPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var User $target */
        $target = $this->route('user');

        return $this->user()?->can('resetPassword', $target) ?? false;
    }

    public function rules(): array
    {
        return [
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }
}
