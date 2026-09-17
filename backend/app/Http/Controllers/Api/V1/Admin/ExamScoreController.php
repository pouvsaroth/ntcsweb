<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\RecordExamScoresRequest;
use App\Http\Resources\ExamScoreEntryResource;
use App\Http\Responses\ApiResponse;
use App\Models\ExamScore;
use App\Services\Academic\ExamScoreService;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Grades tab — see ExamScoreService. Three ways in, all the same list:
 * by course (course_package_id + class_id + book_id), by one student
 * (student_id), or everything (no filters, optionally `scored=0`).
 */
final class ExamScoreController extends Controller
{
    public function __construct(private readonly ExamScoreService $scores) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ExamScore::class);

        $filters = $request->validate([
            'course_package_id' => ['nullable', 'integer'],
            'class_id' => ['nullable', 'integer'],
            'book_id' => ['nullable', 'integer'],
            'student_id' => ['nullable', 'integer'],
            'scored' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        if (array_key_exists('scored', $filters) && $filters['scored'] !== null) {
            $filters['scored'] = $request->boolean('scored');
        }

        $query = $this->scores->filtered($request->user(), $filters)->orderBy('id');

        $rows = ApiQuery::for($query, $request)->maxPerPage(500)->paginate();

        return ApiResponse::success(ExamScoreEntryResource::collection($rows));
    }

    /** GET /exam-scores/options — Course → Class → Book combinations for the pickers. */
    public function options(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ExamScore::class);

        return ApiResponse::success($this->scores->options($request->user()));
    }

    public function store(RecordExamScoresRequest $request): JsonResponse
    {
        $this->scores->record($request->validated('entries'), $request->user());

        return ApiResponse::success(['saved' => count($request->validated('entries'))]);
    }
}
