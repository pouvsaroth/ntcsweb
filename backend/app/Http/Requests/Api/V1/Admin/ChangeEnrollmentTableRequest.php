<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Enrollment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Reseats a student within their *current* class — deliberately has no
 * `class_id`/`course_package_id` fields at all (unlike TransferEnrollmentRequest,
 * which this intentionally does not reuse), so there is no way to smuggle a
 * class or course change through this endpoint even if the caller tried.
 */
class ChangeEnrollmentTableRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Enrollment $enrollment */
        $enrollment = $this->route('enrollment');

        return $this->user()?->can('changeTable', $enrollment) ?? false;
    }

    public function rules(): array
    {
        return [
            'table_id' => ['nullable', Rule::exists('tenant.classroom_tables', 'id')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->has('table_id')) {
                return;
            }

            /** @var Enrollment $enrollment */
            $enrollment = $this->route('enrollment');

            $class = DB::connection('tenant')->table('classes')->where('id', $enrollment->class_id)->first();
            if ($class === null || $class->classroom_id === null) {
                return;
            }

            $hasTables = DB::connection('tenant')->table('classroom_tables')->where('classroom_id', $class->classroom_id)->exists();
            if (! $hasTables) {
                return;
            }

            $tableId = $this->input('table_id');

            if ($tableId === null) {
                $validator->errors()->add('table_id', __('Pick a table for this class.'));

                return;
            }

            $belongsToRoom = DB::connection('tenant')->table('classroom_tables')->where('id', $tableId)->where('classroom_id', $class->classroom_id)->exists();
            if (! $belongsToRoom) {
                $validator->errors()->add('table_id', __("This table does not belong to the student's class room."));

                return;
            }

            $taken = DB::connection('tenant')->table('enrollments')
                ->where('class_id', $enrollment->class_id)
                ->where('table_id', $tableId)
                ->where('status', '!=', Enrollment::STATUS_DROPPED)
                ->where('id', '!=', $enrollment->id)
                ->exists();

            if ($taken) {
                $validator->errors()->add('table_id', __('This table is already taken in this class.'));
            }
        });
    }
}
