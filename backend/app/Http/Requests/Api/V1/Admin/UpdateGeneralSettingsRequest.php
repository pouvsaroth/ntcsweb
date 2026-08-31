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
        foreach (['student_id_prefix', 'invoice_prefix', 'receipt_prefix'] as $field) {
            if ($this->has($field)) {
                $this->merge([
                    $field => Str::upper(trim((string) $this->input($field))),
                ]);
            }
        }
    }

    public function rules(): array
    {
        // Same shape for all three: uppercase letters/digits only, no `-`
        // (which would make `{prefix}-{number}` ambiguous to parse back
        // apart — invoice/receipt numbers additionally interpose a year,
        // e.g. `INV-2026-000001`, so the prefix itself must stay dash-free).
        $prefixRule = ['string', 'max:20', 'regex:/^[A-Z0-9]+$/'];

        return [
            'student_id_prefix' => ['sometimes', 'required', ...$prefixRule],
            'invoice_prefix' => ['sometimes', 'required', ...$prefixRule],
            'receipt_prefix' => ['sometimes', 'required', ...$prefixRule],
        ];
    }
}
