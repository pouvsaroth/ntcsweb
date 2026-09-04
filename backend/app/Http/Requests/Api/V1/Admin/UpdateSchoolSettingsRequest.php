<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Deliberately only the fields TenantPolicy::update() describes as a school
 * admin's to change — `status`, `slug`, `code` and domains stay platform-only
 * by simply having no rule here, so a FormRequest silently drops them even if
 * someone sends them.
 */
class UpdateSchoolSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(Permissions::TENANT_SETTINGS_UPDATE) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:500'],
            // Drives every invoice's own language (see resources/views/pdf/invoice.blade.php
            // and lang/{locale}/invoice.php) — ResolveTenant already applies this tenant-wide
            // on every request via app()->setLocale(). Limited to what invoice.php actually
            // has translations for today; a locale outside this list would silently fall back
            // to English labels rather than error, but there's no reason to offer it yet.
            'locale' => ['sometimes', 'required', Rule::in(['en', 'km'])],
            // 10M matches upload_max_filesize in docker/php/uploads.ini.
            'logo' => ['nullable', 'image', 'mimes:jpeg,png,webp,gif', 'max:10240'],
            // The raw KHQR string decoded from the school's own bank app —
            // see App\Support\Billing\Khqr. Not validated as a real KHQR
            // payload here (that would mean re-parsing it, the exact risk
            // Khqr's own docblock explains avoiding); Khqr::withAmount()
            // fails loudly at first use if this is garbage.
            'khqr_template' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
