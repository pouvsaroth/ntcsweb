<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Self-service only — there is no route parameter here, this always acts on
 * `$request->user()` (see AuthController::updateProfile()). Deliberately has
 * no `email`/`password`/`role` field: email changes aren't in scope here, and
 * a password change has its own dedicated, re-authenticated endpoint
 * (ChangePasswordRequest) for a reason worth keeping separate.
 */
class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $user = $this->user();

        return [
            'name' => ['required', 'string', 'max:255'],

            // Same tenant-scoped (or platform-scoped, for a super admin)
            // uniqueness the users table itself enforces — checked here too
            // so a collision surfaces as a clean 422 instead of a raw DB
            // constraint violation.
            'phone' => [
                'required', 'string', 'max:32',
                Rule::unique('users', 'phone')
                    ->where(fn ($query) => $user->tenant_id === null ? $query->whereNull('tenant_id') : $query->where('tenant_id', $user->tenant_id))
                    ->ignore($user->getKey()),
            ],

            // Same shape as HomeSlide's/Student's upload, tighter size cap —
            // an avatar has no legitimate reason to be as large as a homepage
            // slide image.
            'avatar' => ['nullable', 'image', 'mimes:jpeg,png,webp,gif', 'max:2048'],
        ];
    }
}
