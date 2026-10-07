<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\EvaluationForm;
use App\Models\EvaluationFormQuestion;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * HRM > Performance Management > Evaluation forms — forms and their
 * questions; `questions` replaces the whole list when sent (questions keep
 * their id when sent back with it, so answers given to them stay theirs).
 */
final class EvaluationFormController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', EvaluationForm::class);

        $forms = ApiQuery::for(EvaluationForm::query()->with('questions')->withCount('cycles'), $request)
            ->searchable('name')
            ->filterable(['is_active'])
            ->sortable(['name'], default: 'name')
            ->maxPerPage(200)
            ->paginate();

        return ApiResponse::success($forms->through(fn (EvaluationForm $form) => $this->row($form)));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', EvaluationForm::class);
        $data = $this->validated($request, true);

        $form = DB::connection('tenant')->transaction(function () use ($data) {
            $form = EvaluationForm::query()->create(collect($data)->except('questions')->all());
            $this->syncQuestions($form, $data['questions'] ?? []);

            return $form;
        });

        return ApiResponse::created($this->row($form->load('questions')->loadCount('cycles')));
    }

    public function show(EvaluationForm $evaluationForm): JsonResponse
    {
        $this->authorize('view', $evaluationForm);

        return ApiResponse::success($this->row($evaluationForm->load('questions')->loadCount('cycles')));
    }

    public function update(Request $request, EvaluationForm $evaluationForm): JsonResponse
    {
        $this->authorize('update', $evaluationForm);
        $data = $this->validated($request, false);

        DB::connection('tenant')->transaction(function () use ($evaluationForm, $data) {
            $evaluationForm->update(collect($data)->except('questions')->all());
            if (array_key_exists('questions', $data)) {
                $this->syncQuestions($evaluationForm, $data['questions']);
            }
        });

        return ApiResponse::success($this->row($evaluationForm->load('questions')->loadCount('cycles')));
    }

    public function destroy(EvaluationForm $evaluationForm): JsonResponse
    {
        $this->authorize('delete', $evaluationForm);

        if ($evaluationForm->cycles()->exists()) {
            return ApiResponse::error('A review cycle uses this form, so it cannot be deleted. Deactivate it instead.', 422);
        }

        $evaluationForm->delete();

        return ApiResponse::noContent();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
            'questions' => [$creating ? 'sometimes' : 'sometimes', 'array', 'max:100'],
            'questions.*.id' => ['nullable', 'integer'],
            'questions.*.section' => ['nullable', 'string', 'max:255'],
            'questions.*.question' => ['required', 'string', 'max:2000'],
            'questions.*.type' => ['required', Rule::in(EvaluationFormQuestion::TYPES)],
            'questions.*.is_required' => ['sometimes', 'boolean'],
        ]);
    }

    /** @param  list<array<string, mixed>>  $questions */
    private function syncQuestions(EvaluationForm $form, array $questions): void
    {
        // validate() rebuilds the list rule by rule, so a question with an
        // id can come back ahead of one without — put them back in the
        // order they were sent.
        ksort($questions);
        $keep = collect($questions)->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();
        $form->questions()->getQuery()->whereNotIn('id', $keep)->delete();

        foreach (array_values($questions) as $order => $question) {
            $attributes = [
                'section' => $question['section'] ?? null,
                'question' => $question['question'],
                'type' => $question['type'],
                'is_required' => $question['is_required'] ?? true,
                'sort_order' => $order,
            ];
            $existing = isset($question['id']) ? EvaluationFormQuestion::query()->where('evaluation_form_id', $form->id)->find($question['id']) : null;
            $existing !== null ? $existing->update($attributes) : $form->questions()->create($attributes);
        }
    }

    /** @return array<string, mixed> */
    private function row(EvaluationForm $form): array
    {
        return [
            'id' => $form->id,
            'name' => $form->name,
            'description' => $form->description,
            'is_active' => $form->is_active,
            'cycles_count' => $form->cycles_count ?? null,
            'questions' => $form->questions->map(fn (EvaluationFormQuestion $q) => [
                'id' => $q->id,
                'section' => $q->section,
                'question' => $q->question,
                'type' => $q->type,
                'is_required' => $q->is_required,
            ])->values(),
        ];
    }
}
