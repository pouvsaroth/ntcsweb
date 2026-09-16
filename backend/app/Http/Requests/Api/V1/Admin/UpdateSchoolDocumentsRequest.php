<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Uploads either or both of the two fixed documents — see
 * SchoolDocumentsContent. Neither file is required on a given request, so a
 * school can replace just one without re-uploading the other.
 */
class UpdateSchoolDocumentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(Permissions::TENANT_SETTINGS_UPDATE) ?? false;
    }

    public function rules(): array
    {
        return [
            // 10M matches upload_max_filesize in docker/php/uploads.ini.
            'school_regulation' => ['nullable', 'file', 'mimes:pdf,doc,docx', 'max:10240'],
            'student_attendance_policy' => ['nullable', 'file', 'mimes:pdf,doc,docx', 'max:10240'],
        ];
    }
}
