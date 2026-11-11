<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Student;
use App\Services\Academic\StudyingStudentsList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Students > Export: the students studying in a class right now, with the
 * picked columns, total and female count — on screen (for the image),
 * Excel or PDF. Same filters as the Students list (search, class, year).
 */
final class StudyingStudentsController extends Controller
{
    public function __construct(private readonly StudyingStudentsList $lists) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success($this->build($request));
    }

    public function excel(Request $request): Response
    {
        return $this->download($this->lists->excel($this->build($request)), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xlsx');
    }

    public function pdf(Request $request): Response
    {
        return $this->download($this->lists->pdf($this->build($request)), 'application/pdf', 'pdf');
    }

    /** @return array<string, mixed> */
    private function build(Request $request): array
    {
        $this->authorize('viewAny', Student::class);

        $data = $request->validate([
            'columns' => ['nullable', 'string', 'max:500'],
            'search' => ['nullable', 'string', 'max:255'],
            'class_id' => ['nullable', 'integer'],
            'academic_year_id' => ['nullable', 'integer'],
        ]);

        return $this->lists->build(
            [
                'search' => $data['search'] ?? null,
                'class_id' => isset($data['class_id']) ? (int) $data['class_id'] : null,
                'academic_year_id' => isset($data['academic_year_id']) ? (int) $data['academic_year_id'] : null,
                'class_ids' => app(\App\Support\Authorization\ClassAccess::class)->visibleClassIds($request->user()),
            ],
            StudyingStudentsList::pickColumns($data['columns'] ?? null),
        );
    }

    private function download(string $bytes, string $type, string $extension): Response
    {
        return response($bytes, 200, [
            'Content-Type' => $type,
            'Content-Disposition' => 'attachment; filename="studying-students-'.now()->format('Y-m-d').'.'.$extension.'"',
        ]);
    }
}
