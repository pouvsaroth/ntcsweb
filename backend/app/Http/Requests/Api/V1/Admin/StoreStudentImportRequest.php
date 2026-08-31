<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Student;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Http\FormRequest;

class StoreStudentImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permissions::STUDENTS_CREATE, Student::class) ?? false;
    }

    public function rules(): array
    {
        return [
            // Matches upload_max_filesize/post_max_size in
            // docker/php/uploads.ini — a real legacy export of a few thousand
            // students is well under this, but the limit exists so a
            // mismatched file doesn't fail with a bare PHP-level cutoff.
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ];
    }
}
