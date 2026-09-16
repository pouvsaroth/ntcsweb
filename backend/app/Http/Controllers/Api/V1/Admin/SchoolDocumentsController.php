<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\UpdateSchoolDocumentsRequest;
use App\Http\Responses\ApiResponse;
use App\Support\Content\SchoolDocumentsContent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

/**
 * A singleton, not a REST resource — exactly two fixed documents per school,
 * stored in `tenants.settings->documents` (see SchoolDocumentsContent), same
 * shape as AboutPageController.
 */
final class SchoolDocumentsController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function show(): JsonResponse
    {
        $tenant = $this->context->getOrFail();
        $this->authorize('view', $tenant);

        return ApiResponse::success(SchoolDocumentsContent::forTenant($tenant));
    }

    public function update(UpdateSchoolDocumentsRequest $request): JsonResponse
    {
        $tenant = $this->context->getOrFail();
        $this->authorize('update', $tenant);

        // Read-modify-write on the same JSON column every other setting also
        // lives in — see AboutPageController::update() for why this refresh
        // matters.
        $tenant->refresh();
        $documents = $tenant->setting('documents') ?? [];

        foreach (['school_regulation' => 'school_regulation_path', 'student_attendance_policy' => 'student_attendance_policy_path'] as $field => $pathKey) {
            if (! $request->hasFile($field)) {
                continue;
            }

            $newPath = $request->file($field)->store($tenant->storagePath('documents'), 'public');

            if ($newPath === false) {
                abort(500, 'Failed to store the uploaded file.');
            }

            $oldPath = $documents[$pathKey] ?? null;
            if ($oldPath !== null) {
                Storage::disk('public')->delete($oldPath);
            }

            $documents[$pathKey] = $newPath;
        }

        $tenant->update([
            'settings' => [
                ...($tenant->settings ?? []),
                'documents' => $documents,
            ],
        ]);

        return ApiResponse::success(SchoolDocumentsContent::forTenant($tenant->fresh()));
    }
}
