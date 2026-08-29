<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreStudentImportRequest;
use App\Http\Resources\StudentImportResource;
use App\Http\Responses\ApiResponse;
use App\Jobs\ProcessStudentImport;
use App\Models\Student;
use App\Models\StudentImport;
use App\Support\Query\ApiQuery;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StudentImportController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Student::class);

        $imports = ApiQuery::for(StudentImport::query(), $request)
            ->filterable(['status'])
            ->sortable(['created_at'], default: '-created_at')
            ->paginate();

        return ApiResponse::success(StudentImportResource::collection($imports));
    }

    public function store(StoreStudentImportRequest $request): JsonResponse
    {
        $tenant = $this->context->getOrFail();

        $file = $request->file('file');
        $path = $file->store($tenant->storagePath('student-imports'), 'local');

        if ($path === false) {
            abort(500, 'Failed to store the uploaded file.');
        }

        $import = StudentImport::query()->create([
            'user_id' => $request->user()?->id,
            'original_filename' => $file->getClientOriginalName(),
            'file_path' => $path,
            'status' => StudentImport::STATUS_PENDING,
        ]);

        ProcessStudentImport::dispatch($import);

        return ApiResponse::created(new StudentImportResource($import));
    }

    public function show(StudentImport $student_import): JsonResponse
    {
        $this->authorize('viewAny', Student::class);

        return ApiResponse::success(new StudentImportResource($student_import));
    }
}
