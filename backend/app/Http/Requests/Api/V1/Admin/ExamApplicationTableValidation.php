<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Validator;

/**
 * Shared by StoreExamApplicationRequest/UpdateExamApplicationRequest: a
 * picked table must actually belong to the picked classroom — the two
 * dropdowns aren't independently validated by `exists` rules alone, since
 * either one could reference something real but mismatched.
 */
final class ExamApplicationTableValidation
{
    public static function ensureTableBelongsToClassroom(Validator $validator, FormRequest $request): void
    {
        $validator->after(function (Validator $validator) use ($request) {
            $tableId = $request->input('table_id');
            $classroomId = $request->input('classroom_id');

            if ($tableId === null || $classroomId === null) {
                return;
            }

            $belongs = DB::connection('tenant')->table('classroom_tables')
                ->where('id', $tableId)
                ->where('classroom_id', $classroomId)
                ->exists();

            if (! $belongs) {
                $validator->errors()->add('table_id', __('This table does not belong to the selected room.'));
            }
        });
    }
}
