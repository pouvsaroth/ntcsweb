<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\ObservationForm;
use App\Models\ObservationFormCriterion;
use App\Support\Authorization\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * HRM > Professional Development > Classroom observation > Forms
 * (docs/hrm/professional-development.md): the school's observation forms —
 * criteria grouped by section, each rated 1–5 stars. Observations copy the
 * criteria, so editing a form never changes past observations.
 */
final class ObservationFormController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission(Permissions::PROFESSIONAL_DEVELOPMENT_VIEW), 403);

        $forms = ObservationForm::query()->with('criteria')->orderByDesc('is_active')->orderBy('name')->get();

        return ApiResponse::success($forms->map(fn (ObservationForm $f) => $this->row($f))->values());
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission(Permissions::PROFESSIONAL_DEVELOPMENT_MANAGE), 403);
        $data = $this->validated($request);

        $form = DB::connection('tenant')->transaction(function () use ($data) {
            $form = ObservationForm::query()->create(['name' => $data['name'], 'description' => $data['description'] ?? null, 'is_active' => $data['is_active'] ?? true]);
            $this->saveCriteria($form, $data['criteria']);

            return $form;
        });

        return ApiResponse::created($this->row($form->load('criteria')));
    }

    public function update(Request $request, ObservationForm $observationForm): JsonResponse
    {
        abort_unless($request->user()->hasPermission(Permissions::PROFESSIONAL_DEVELOPMENT_MANAGE), 403);
        $data = $this->validated($request);

        DB::connection('tenant')->transaction(function () use ($observationForm, $data) {
            $observationForm->update(['name' => $data['name'], 'description' => $data['description'] ?? null, 'is_active' => $data['is_active'] ?? $observationForm->is_active]);
            $this->saveCriteria($observationForm, $data['criteria']);
        });

        return ApiResponse::success($this->row($observationForm->load('criteria')));
    }

    public function destroy(Request $request, ObservationForm $observationForm): JsonResponse
    {
        abort_unless($request->user()->hasPermission(Permissions::PROFESSIONAL_DEVELOPMENT_MANAGE), 403);

        // Past observations keep their own copy of the criteria and the form's name.
        $observationForm->delete();

        return ApiResponse::noContent();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
            'criteria' => ['required', 'array', 'min:1', 'max:100'],
            'criteria.*.section' => ['nullable', 'string', 'max:200'],
            'criteria.*.criterion' => ['required', 'string', 'max:1000'],
        ]);
    }

    /** @param  list<array{section?: ?string, criterion: string}>  $criteria */
    private function saveCriteria(ObservationForm $form, array $criteria): void
    {
        ObservationFormCriterion::query()->where('observation_form_id', $form->id)->delete();
        foreach (array_values($criteria) as $i => $c) {
            ObservationFormCriterion::query()->create([
                'observation_form_id' => $form->id,
                'section' => isset($c['section']) && trim((string) $c['section']) !== '' ? trim((string) $c['section']) : null,
                'criterion' => trim($c['criterion']),
                'sort_order' => $i,
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function row(ObservationForm $f): array
    {
        return [
            'id' => $f->id,
            'name' => $f->name,
            'description' => $f->description,
            'is_active' => $f->is_active,
            'criteria' => $f->criteria->map(fn (ObservationFormCriterion $c) => ['id' => $c->id, 'section' => $c->section, 'criterion' => $c->criterion])->values(),
        ];
    }
}
