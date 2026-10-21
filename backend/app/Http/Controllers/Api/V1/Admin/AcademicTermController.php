<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AcademicTerm;
use App\Support\Authorization\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Academics > School setup > Terms / semesters (docs/academics/school-setup.md). */
final class AcademicTermController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasAnyPermission(Permissions::ACADEMIC_TERMS_VIEW, Permissions::SCHOOL_CALENDAR_VIEW), 403);

        $terms = AcademicTerm::query()->with('academicYear:id,name')
            ->when($request->filled('academic_year_id'), fn ($q) => $q->where('academic_year_id', $request->integer('academic_year_id')))
            ->orderBy('academic_year_id', 'desc')->orderBy('sort_order')->orderBy('start_date')->orderBy('id')
            ->get();

        return ApiResponse::success($terms->map(fn (AcademicTerm $t) => $this->row($t))->values());
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission(Permissions::ACADEMIC_TERMS_MANAGE), 403);

        $term = AcademicTerm::query()->create($this->validated($request));

        return ApiResponse::created($this->row($term->load('academicYear:id,name')));
    }

    public function update(Request $request, AcademicTerm $academicTerm): JsonResponse
    {
        abort_unless($request->user()->hasPermission(Permissions::ACADEMIC_TERMS_MANAGE), 403);

        $academicTerm->update($this->validated($request, $academicTerm));

        return ApiResponse::success($this->row($academicTerm->load('academicYear:id,name')));
    }

    public function destroy(Request $request, AcademicTerm $academicTerm): JsonResponse
    {
        abort_unless($request->user()->hasPermission(Permissions::ACADEMIC_TERMS_MANAGE), 403);

        $academicTerm->delete();

        return ApiResponse::noContent();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?AcademicTerm $term = null): array
    {
        $yearId = $request->input('academic_year_id', $term?->academic_year_id);

        $data = $request->validate([
            'academic_year_id' => ['required', 'integer', Rule::exists('tenant.academic_years', 'id')->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:50',
                Rule::unique('tenant.academic_terms', 'name')->where('academic_year_id', $yearId)->ignore($term?->getKey())],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
        ]);
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);

        return $data;
    }

    /** @return array<string, mixed> */
    private function row(AcademicTerm $t): array
    {
        return [
            'id' => $t->id,
            'academic_year_id' => $t->academic_year_id,
            'academic_year' => $t->academicYear?->name,
            'name' => $t->name,
            'start_date' => $t->start_date?->toDateString(),
            'end_date' => $t->end_date?->toDateString(),
            'sort_order' => $t->sort_order,
        ];
    }
}
