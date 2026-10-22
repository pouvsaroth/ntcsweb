<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AssessmentComponent;
use App\Models\AssessmentScheme;
use App\Models\CompetencyCriterion;
use App\Models\CompetencyLevel;
use App\Models\GradeEntry;
use App\Support\Authorization\Permissions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Academic > Examination > Assessment Setup (docs/academics/gradebook.md):
 * one scheme per grade level (or the school's default), saved whole — the
 * scheme and its components, bands, competency criteria and levels in one
 * request. Children keep their ids so later results stay attached.
 */
final class AssessmentSchemeController extends Controller
{
    private const WITH = ['gradeLevel:id,code,name,level_order', 'components', 'bands', 'criteria.book:id,title', 'levels'];

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasAnyPermission(Permissions::ASSESSMENT_VIEW, Permissions::EXAM_SCHEDULES_VIEW), 403);

        $schemes = AssessmentScheme::query()->with(self::WITH)->get()
            // The default first, then grades in their order.
            ->sortBy(fn (AssessmentScheme $s) => $s->grade_level_id === null ? -1 : (int) $s->gradeLevel?->level_order)
            ->values();

        return ApiResponse::success($schemes->map(fn (AssessmentScheme $s) => $this->row($s)));
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission(Permissions::ASSESSMENT_MANAGE), 403);

        $data = $this->validated($request);
        $scheme = DB::connection('tenant')->transaction(function () use ($data) {
            $scheme = AssessmentScheme::query()->create($this->schemeFields($data));
            $this->syncChildren($scheme, $data);

            return $scheme;
        });

        return ApiResponse::created($this->row($scheme->load(self::WITH)));
    }

    public function update(Request $request, AssessmentScheme $assessmentScheme): JsonResponse
    {
        abort_unless($request->user()->hasPermission(Permissions::ASSESSMENT_MANAGE), 403);

        $data = $this->validated($request, $assessmentScheme);
        DB::connection('tenant')->transaction(function () use ($assessmentScheme, $data) {
            $assessmentScheme->update($this->schemeFields($data));
            $this->syncChildren($assessmentScheme, $data);
        });

        return ApiResponse::success($this->row($assessmentScheme->fresh(self::WITH)));
    }

    public function destroy(Request $request, AssessmentScheme $assessmentScheme): JsonResponse
    {
        abort_unless($request->user()->hasPermission(Permissions::ASSESSMENT_MANAGE), 403);

        $assessmentScheme->delete();

        return ApiResponse::noContent();
    }

    /**
     * A starting point the school must check against the Ministry's current
     * guidance: score mode — monthly tests (×3) 50 % + semester exam 50 %,
     * bands A–F; competency mode — four levels and three general criteria.
     * Only fills what is still empty.
     */
    public function starter(Request $request, AssessmentScheme $assessmentScheme): JsonResponse
    {
        abort_unless($request->user()->hasPermission(Permissions::ASSESSMENT_MANAGE), 403);

        DB::connection('tenant')->transaction(function () use ($assessmentScheme) {
            if ($assessmentScheme->mode === AssessmentScheme::MODE_SCORE) {
                if (! $assessmentScheme->components()->exists()) {
                    $assessmentScheme->components()->createMany([
                        ['name' => 'Monthly test', 'name_km' => 'ប្រឡងប្រចាំខែ', 'kind' => 'monthly', 'max_score' => 100, 'weight' => 50, 'occurrences' => 3, 'sort_order' => 1],
                        ['name' => 'Semester exam', 'name_km' => 'ប្រឡងឆមាស', 'kind' => 'exam', 'max_score' => 100, 'weight' => 50, 'occurrences' => 1, 'sort_order' => 2],
                    ]);
                }
                if (! $assessmentScheme->bands()->exists()) {
                    $assessmentScheme->bands()->createMany([
                        ['letter' => 'A', 'label' => 'Excellent', 'label_km' => 'ល្អប្រសើរ', 'min_percent' => 90],
                        ['letter' => 'B', 'label' => 'Very good', 'label_km' => 'ល្អណាស់', 'min_percent' => 80],
                        ['letter' => 'C', 'label' => 'Good', 'label_km' => 'ល្អ', 'min_percent' => 70],
                        ['letter' => 'D', 'label' => 'Fairly good', 'label_km' => 'ល្អបង្គួរ', 'min_percent' => 60],
                        ['letter' => 'E', 'label' => 'Average', 'label_km' => 'មធ្យម', 'min_percent' => 50],
                        ['letter' => 'F', 'label' => 'Weak', 'label_km' => 'ខ្សោយ', 'min_percent' => 0],
                    ]);
                }
            } else {
                if (! $assessmentScheme->levels()->exists()) {
                    $assessmentScheme->levels()->createMany([
                        ['code' => '4', 'label' => 'Very good', 'label_km' => 'ល្អណាស់', 'points' => 4, 'is_passing' => true, 'sort_order' => 1],
                        ['code' => '3', 'label' => 'Good', 'label_km' => 'ល្អ', 'points' => 3, 'is_passing' => true, 'sort_order' => 2],
                        ['code' => '2', 'label' => 'Fair', 'label_km' => 'មធ្យម', 'points' => 2, 'is_passing' => true, 'sort_order' => 3],
                        ['code' => '1', 'label' => 'Needs support', 'label_km' => 'ត្រូវការជំនួយ', 'points' => 1, 'is_passing' => false, 'sort_order' => 4],
                    ]);
                }
                if (! $assessmentScheme->criteria()->exists()) {
                    $assessmentScheme->criteria()->createMany([
                        ['name' => 'Knowledge', 'name_km' => 'ចំណេះដឹង', 'sort_order' => 1],
                        ['name' => 'Skills', 'name_km' => 'បំណិន', 'sort_order' => 2],
                        ['name' => 'Attitude', 'name_km' => 'ឥរិយាបថ', 'sort_order' => 3],
                    ]);
                }
            }
        });

        return ApiResponse::success($this->row($assessmentScheme->fresh(self::WITH)));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?AssessmentScheme $scheme = null): array
    {
        $data = $request->validate([
            'grade_level_id' => ['nullable', 'integer', Rule::exists('tenant.grade_levels', 'id'),
                Rule::unique('tenant.assessment_schemes', 'grade_level_id')->ignore($scheme?->getKey())],
            'mode' => ['required', Rule::in(AssessmentScheme::MODES)],
            'name' => ['required', 'string', 'max:150'],
            'max_score' => ['required', 'numeric', 'gt:0', 'max:1000'],
            'decimals' => ['sometimes', 'integer', 'between:0,2'],
            'absent_counts_as_zero' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],

            'components' => ['sometimes', 'array', 'max:20'],
            'components.*.id' => ['nullable', 'integer'],
            'components.*.name' => ['required', 'string', 'max:100'],
            'components.*.name_km' => ['nullable', 'string', 'max:100'],
            'components.*.kind' => ['required', Rule::in(AssessmentComponent::KINDS)],
            'components.*.max_score' => ['required', 'numeric', 'gt:0', 'max:1000'],
            'components.*.weight' => ['required', 'numeric', 'gt:0', 'max:100'],
            'components.*.occurrences' => ['required', 'integer', 'between:1,12'],

            'bands' => ['sometimes', 'array', 'max:20'],
            'bands.*.id' => ['nullable', 'integer'],
            'bands.*.letter' => ['required', 'string', 'max:5', 'distinct:ignore_case'],
            'bands.*.label' => ['nullable', 'string', 'max:50'],
            'bands.*.label_km' => ['nullable', 'string', 'max:50'],
            'bands.*.min_percent' => ['required', 'numeric', 'between:0,100'],

            'criteria' => ['sometimes', 'array', 'max:50'],
            'criteria.*.id' => ['nullable', 'integer'],
            'criteria.*.book_id' => ['nullable', 'integer', Rule::exists('tenant.books', 'id')],
            'criteria.*.name' => ['required', 'string', 'max:150'],
            'criteria.*.name_km' => ['nullable', 'string', 'max:150'],

            'levels' => ['sometimes', 'array', 'max:10'],
            'levels.*.id' => ['nullable', 'integer'],
            'levels.*.code' => ['required', 'string', 'max:10', 'distinct:ignore_case'],
            'levels.*.label' => ['required', 'string', 'max:50'],
            'levels.*.label_km' => ['nullable', 'string', 'max:50'],
            'levels.*.points' => ['required', 'numeric', 'between:0,100'],
            'levels.*.is_passing' => ['sometimes', 'boolean'],
        ]);

        // Only one default: a null grade level is not caught by the unique index.
        if (($data['grade_level_id'] ?? null) === null
            && AssessmentScheme::query()->whereNull('grade_level_id')->when($scheme, fn ($q) => $q->whereKeyNot($scheme->getKey()))->exists()) {
            throw ValidationException::withMessages(['grade_level_id' => __('The school already has a default assessment setup. Pick a grade level.')]);
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function schemeFields(array $data): array
    {
        return [
            'grade_level_id' => $data['grade_level_id'] ?? null,
            'mode' => $data['mode'],
            'name' => $data['name'],
            'max_score' => $data['max_score'],
            'decimals' => $data['decimals'] ?? 2,
            'absent_counts_as_zero' => $data['absent_counts_as_zero'] ?? true,
            'notes' => $data['notes'] ?? null,
        ];
    }

    private function syncChildren(AssessmentScheme $scheme, array $data): void
    {
        $order = fn (array $items) => array_map(fn ($item, $i) => $item + ['sort_order' => $i + 1], $items, array_keys($items));

        if (array_key_exists('components', $data)) {
            $this->sync($scheme->components(), $order($data['components']), ['name', 'name_km', 'kind', 'max_score', 'weight', 'occurrences', 'sort_order']);
        }
        if (array_key_exists('bands', $data)) {
            $this->sync($scheme->bands(), $data['bands'], ['letter', 'label', 'label_km', 'min_percent']);
        }
        if (array_key_exists('criteria', $data)) {
            $this->sync($scheme->criteria(), $order($data['criteria']), ['book_id', 'name', 'name_km', 'sort_order']);
        }
        if (array_key_exists('levels', $data)) {
            $this->sync($scheme->levels(), $order(array_map(fn ($l) => $l + ['is_passing' => true], $data['levels'])), ['code', 'label', 'label_km', 'points', 'is_passing', 'sort_order']);
        }
    }

    /**
     * Rows with a known id are updated, rows without one are added, the
     * scheme's other rows are removed. An id from another scheme is ignored
     * (treated as new) — never moved across.
     *
     * @param  HasMany<Model, AssessmentScheme>  $relation
     * @param  list<array<string, mixed>>  $items
     * @param  list<string>  $fields
     */
    private function sync(HasMany $relation, array $items, array $fields): void
    {
        $existing = $relation->getQuery()->clone()->get()->keyBy('id');
        // Delete first, so a letter/code moved between rows does not clash with the unique index.
        $incomingIds = array_filter(array_map(fn ($i) => isset($i['id']) ? (int) $i['id'] : null, $items));
        $leaving = $relation->getQuery()->clone()->whereNotIn('id', $incomingIds ?: [0])->get();
        $this->ensureUnused($leaving);
        $leaving->each->delete();

        foreach ($items as $item) {
            $values = array_intersect_key($item, array_flip($fields));
            foreach ($fields as $field) {
                $values[$field] ??= null;
            }
            $id = isset($item['id']) ? (int) $item['id'] : null;
            if ($id !== null && $existing->has($id)) {
                $existing->get($id)->update($values);
            } else {
                $relation->create($values);
            }
        }
    }

    /**
     * A component, criterion or level that already has scores entered cannot
     * be removed (the scores would lose their meaning); change it instead.
     *
     * @param  Collection<int, Model>  $rows
     */
    private function ensureUnused(Collection $rows): void
    {
        $column = [
            AssessmentComponent::class => 'assessment_component_id',
            CompetencyCriterion::class => 'competency_criterion_id',
            CompetencyLevel::class => 'competency_level_id',
        ];
        foreach ($rows as $row) {
            $col = $column[$row::class] ?? null;
            if ($col !== null && GradeEntry::query()->where($col, $row->getKey())->exists()) {
                throw ValidationException::withMessages(['scheme' => __(':name already has scores entered, so it cannot be removed. Change it instead.', ['name' => $row->name ?? $row->label ?? ''])]);
            }
        }
    }

    /** @return array<string, mixed> */
    private function row(AssessmentScheme $s): array
    {
        $weights = round((float) $s->components->sum('weight'), 2);
        $warnings = [];
        if ($s->mode === AssessmentScheme::MODE_SCORE) {
            if ($s->components->isEmpty()) {
                $warnings[] = 'no_components';
            } elseif (abs($weights - 100) > 0.001) {
                $warnings[] = 'weights_not_100';
            }
            if ($s->bands->isEmpty()) {
                $warnings[] = 'no_bands';
            }
        } else {
            if ($s->levels->isEmpty()) {
                $warnings[] = 'no_levels';
            }
            if ($s->criteria->isEmpty()) {
                $warnings[] = 'no_criteria';
            }
        }

        return [
            'id' => $s->id,
            'grade_level' => $s->gradeLevel?->only(['id', 'code', 'name']),
            'mode' => $s->mode,
            'name' => $s->name,
            'max_score' => (float) $s->max_score,
            'decimals' => $s->decimals,
            'absent_counts_as_zero' => $s->absent_counts_as_zero,
            'notes' => $s->notes,
            'weights_total' => $weights,
            'warnings' => $warnings,
            'components' => $s->components->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->name, 'name_km' => $c->name_km, 'kind' => $c->kind,
                'max_score' => (float) $c->max_score, 'weight' => (float) $c->weight, 'occurrences' => $c->occurrences,
            ])->values(),
            'bands' => $s->bands->map(fn ($b) => [
                'id' => $b->id, 'letter' => $b->letter, 'label' => $b->label, 'label_km' => $b->label_km, 'min_percent' => (float) $b->min_percent,
            ])->values(),
            'criteria' => $s->criteria->map(fn ($c) => [
                'id' => $c->id, 'book' => $c->book?->only(['id', 'title']), 'name' => $c->name, 'name_km' => $c->name_km,
            ])->values(),
            'levels' => $s->levels->map(fn ($l) => [
                'id' => $l->id, 'code' => $l->code, 'label' => $l->label, 'label_km' => $l->label_km, 'points' => (float) $l->points, 'is_passing' => $l->is_passing,
            ])->values(),
        ];
    }
}
