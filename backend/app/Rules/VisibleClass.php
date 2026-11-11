<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\Authorization\ClassAccess;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Roles > Data view: the class must be one of the signed-in user's classes. */
final class VisibleClass implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $user = request()->user();
        if ($user !== null && is_numeric($value) && ! app(ClassAccess::class)->canSee($user, (int) $value)) {
            $fail(__('That class is not one of your classes.'));
        }
    }
}
