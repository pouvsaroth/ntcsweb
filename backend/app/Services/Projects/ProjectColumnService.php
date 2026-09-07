<?php

declare(strict_types=1);

namespace App\Services\Projects;

use App\Models\Project;
use App\Models\ProjectColumn;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ProjectColumnService
{
    public function create(Project $project, array $data): ProjectColumn
    {
        $maxOrder = $project->columns()->max('order');
        $nextOrder = $maxOrder === null ? 0 : $maxOrder + 1;

        return $project->columns()->create([
            'name' => $data['name'],
            'color' => $data['color'] ?? null,
            'order' => $nextOrder,
        ]);
    }

    /**
     * Persists a full drag-and-drop reorder of every column on a board.
     *
     * @param  list<int>  $orderedColumnIds
     */
    public function reorder(Project $project, array $orderedColumnIds): void
    {
        DB::transaction(function () use ($project, $orderedColumnIds) {
            $columns = $project->columns()->lockForUpdate()->get()->keyBy('id');

            $isSameSet = count($orderedColumnIds) === count(array_unique($orderedColumnIds))
                && count($orderedColumnIds) === $columns->count()
                && array_diff($orderedColumnIds, $columns->keys()->all()) === [];

            if (! $isSameSet) {
                throw ValidationException::withMessages(['columns' => 'The column list does not match this project\'s columns.']);
            }

            foreach (array_values($orderedColumnIds) as $index => $columnId) {
                $columns[$columnId]->update(['order' => $index]);
            }
        });
    }
}
