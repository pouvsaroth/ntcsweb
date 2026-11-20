<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Enrollment;
use App\Models\ParentStudent;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Parent portal: the signed-in parent's children (parent_students), each
 * with its class now, for the child picker. Identity-gated; empty for anyone
 * who is not a parent.
 */
final class MyChildrenController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $links = ParentStudent::query()->where('user_id', $request->user()->id)->get()->keyBy('student_id');
        $students = Student::query()->whereIn('id', $links->keys())
            ->with(['enrollments' => fn ($q) => $q->where('status', Enrollment::STATUS_ACTIVE)->with('schoolClass:id,name')->latest('id')])
            ->orderBy('first_name')->get();

        return ApiResponse::success($students->map(fn (Student $s) => [
            'id' => $s->id,
            'name' => trim($s->first_name.' '.$s->last_name),
            'english_name' => $s->english_name,
            'student_code' => $s->student_code,
            'photo_url' => $s->photoUrl(),
            'status' => $s->status,
            'relation' => $links->get($s->id)?->relation,
            'classes' => $s->enrollments->map(fn (Enrollment $e) => $e->schoolClass?->name)->filter()->unique()->values(),
        ])->values());
    }
}
