<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use App\Rules\UniqueAccountIdentity;
use Illuminate\Foundation\Http\FormRequest;

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

            // One phone = one account across every customer.
            'phone' => ['required', 'string', 'max:32', UniqueAccountIdentity::phone($user->getKey())],

            // Same shape and size cap as every other image upload in the app
            // (HomeSlide, Student, Staff, ...) — a webcam capture in
            // particular is an uncompressed PNG and routinely exceeds a
            // couple MB, so a tighter cap here just meant this was the one
            // upload in the app that kept rejecting normal photos.
            'avatar' => ['nullable', 'image', 'mimes:jpeg,png,webp,gif', 'max:10240'],
        ];
    }
}
