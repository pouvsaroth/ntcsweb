<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Enrollment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Enrollment::class) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->idOrFail();

        return [
            'student_id' => ['required', Rule::exists('students', 'id')->where('tenant_id', $tenantId)],
            'class_id' => ['required', Rule::exists('classes', 'id')->where('tenant_id', $tenantId)],

            // A student can now take more than one book within the same
            // class session, so the "already enrolled" check is scoped to
            // this exact (student, class, book) triple — this mirrors the
            // DB's own unique(student_id, class_id, book_id) constraint so
            // the rejection is a clean 422, not a 500 from a caught
            // constraint violation. Attached to `book_id` (checking
            // enrollments.book_id = value) with the other two columns
            // pinned via the closure — together that's the full triple.
            'book_id' => [
                'required',
                Rule::exists('books', 'id')->where('tenant_id', $tenantId),
                Rule::unique('enrollments')->where('tenant_id', $tenantId)->where(
                    fn ($query) => $query
                        ->where('student_id', $this->input('student_id'))
                        ->where('class_id', $this->input('class_id'))
                ),
            ],

            // Uniqueness among the class's active enrollments — mirrors the
            // book_id closure above — is checked in withValidator() below,
            // alongside the "is this table even in this class's room" check,
            // since both need the class's classroom_id resolved first.
            'table_id' => ['nullable', Rule::exists('classroom_tables', 'id')->where('tenant_id', $tenantId)],

            'enrolled_at' => ['required', 'date'],

            // The fee is a per-enrollment snapshot, not a live read of the
            // book's own price (see the migration) — the frontend pre-fills
            // this from the chosen book's fee, but the admin can still
            // adjust it (a discount, a scholarship) before saving.
            'fee' => ['required', 'numeric', 'min:0', 'max:999999.99'],

            'status' => ['sometimes', Rule::in([
                Enrollment::STATUS_ACTIVE, Enrollment::STATUS_COMPLETED, Enrollment::STATUS_DROPPED,
            ])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->has('class_id') || $validator->errors()->has('book_id')) {
                return;
            }

            // The book has to actually be on this class session's menu
            // (class_book) — enrolling a student in a book that isn't even
            // offered in that session would be silently meaningless data.
            $onMenu = DB::table('class_book')
                ->where('class_id', $this->input('class_id'))
                ->where('book_id', $this->input('book_id'))
                ->exists();

            if (! $onMenu) {
                $validator->errors()->add('book_id', __('This book is not offered in the selected class.'));
            }
        });

        $validator->after(function (Validator $validator) {
            if ($validator->errors()->has('class_id') || $validator->errors()->has('table_id')) {
                return;
            }

            $class = DB::table('classes')->where('id', $this->input('class_id'))->first();
            if ($class === null || $class->classroom_id === null) {
                return;
            }

            // Only rooms an admin has actually added tables to require a
            // seat pick — a class in an unconfigured room enrolls exactly as
            // it always could.
            $hasTables = DB::table('classroom_tables')->where('classroom_id', $class->classroom_id)->exists();
            if (! $hasTables) {
                return;
            }

            $tableId = $this->input('table_id');

            if ($tableId === null) {
                $validator->errors()->add('table_id', __('Pick a table for this class.'));

                return;
            }

            $belongsToRoom = DB::table('classroom_tables')->where('id', $tableId)->where('classroom_id', $class->classroom_id)->exists();
            if (! $belongsToRoom) {
                $validator->errors()->add('table_id', __("This table does not belong to the selected class's room."));

                return;
            }

            $taken = DB::table('enrollments')
                ->where('class_id', $this->input('class_id'))
                ->where('table_id', $tableId)
                ->where('status', '!=', Enrollment::STATUS_DROPPED)
                ->exists();

            if ($taken) {
                $validator->errors()->add('table_id', __('This table is already taken in this class.'));
            }
        });
    }
}
