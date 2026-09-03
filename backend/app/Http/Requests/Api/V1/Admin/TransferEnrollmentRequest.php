<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Enrollment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class TransferEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Enrollment $enrollment */
        $enrollment = $this->route('enrollment');

        return $this->user()?->can('transfer', $enrollment) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->idOrFail();

        return [
            'class_id' => ['required', Rule::exists('classes', 'id')->where('tenant_id', $tenantId)],
            // Scoped to the TARGET class's room — the old table belonged to
            // a different class/room and is never carried forward, see
            // EnrollmentService::transferClass().
            'table_id' => ['nullable', Rule::exists('classroom_tables', 'id')->where('tenant_id', $tenantId)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->has('class_id') || $validator->errors()->has('table_id')) {
                return;
            }

            $class = DB::table('classes')->where('id', $this->input('class_id'))->first();
            if ($class === null || $class->classroom_id === null) {
                return;
            }

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
