<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Book;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Book::class) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->idOrFail();

        return [
            'title' => ['required', 'string', 'max:255'],
            'author' => ['nullable', 'string', 'max:255'],
            'isbn' => ['nullable', 'string', 'max:32'],
            'publisher' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'cover_image' => ['nullable', 'string', 'max:2048'],
            'quantity' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            // The default fee a new enrollment for this book pre-fills —
            // not what an already-enrolled student is charged, see
            // Enrollment::$fee.
            'fee' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'status' => ['sometimes', Rule::in([Book::STATUS_ACTIVE, Book::STATUS_INACTIVE])],
            // Which academic program(s) this book belongs to — lets a Course
            // Package's book picker filter down to just its own program.
            'program_ids' => ['sometimes', 'array'],
            'program_ids.*' => [Rule::exists('academic_programs', 'id')->where('tenant_id', $tenantId)],
        ];
    }
}
