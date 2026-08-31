<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class UpdateGeneralSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(Permissions::TENANT_SETTINGS_UPDATE) ?? false;
    }

    /**
     * Normalizes before validating, not after — "nts" must fail neither the
     * uppercase-only regex below nor silently save lowercase; it becomes
     * "NTS" before either can happen. Stripped of surrounding/inner
     * whitespace first so "NTS " or " NTS" don't fail the regex on a
     * trailing/leading space that a user very plausibly typed by accident.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('student_id_prefix')) {
            $this->merge([
                'student_id_prefix' => Str::upper(trim((string) $this->input('student_id_prefix'))),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            // Uppercase letters/digits only, no spaces or punctuation — this
            // becomes the literal left half of every Student ID
            // (StudentIdGenerator), and a `-` inside the prefix itself would
            // make `{prefix}-{number}` ambiguous to parse back apart.
            // max:20 leaves room under the students.student_code varchar(32)
            // column even at the full 6-digit sequence plus its separator.
            'student_id_prefix' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9]+$/'],
        ];
    }
}
