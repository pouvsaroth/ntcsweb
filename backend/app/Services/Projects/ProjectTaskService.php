<?php

declare(strict_types=1);

namespace App\Services\Projects;

use App\Models\ProjectColumn;
use App\Models\ProjectTask;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Kanban card creation and drag-and-drop repositioning. move() is the one
 * non-trivial piece: it reindexes `order` to 0..n-1 for every task in both
 * the source and destination column (in one transaction, both locked) so a
 * drop can never leave a gap or a duplicate position, regardless of where in
 * the two columns the card started and ended up.
 */
final class ProjectTaskService
{
    public function create(ProjectColumn $column, User $creator, array $data): ProjectTask
    {
        $maxOrder = $column->tasks()->max('order');
        $nextOrder = $maxOrder === null ? 0 : $maxOrder + 1;

        return $column->tasks()->create([
            'project_id' => $column->project_id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'priority' => $data['priority'] ?? ProjectTask::PRIORITY_MEDIUM,
            'due_date' => $data['due_date'] ?? null,
            'assignee_id' => $data['assignee_id'] ?? null,
            'order' => $nextOrder,
            'created_by' => $creator->getKey(),
        ]);
    }

    public function move(ProjectTask $task, ProjectColumn $destination, int $targetIndex): ProjectTask
    {
        if ($destination->project_id !== $task->project_id) {
            throw ValidationException::withMessages(['project_column_id' => 'That column does not belong to this task\'s project.']);
        }

        return DB::transaction(function () use ($task, $destination, $targetIndex) {
            $sourceColumnId = $task->project_column_id;

            $sourceSiblings = ProjectTask::query()
                ->where('project_column_id', $sourceColumnId)
                ->lockForUpdate()
                ->orderBy('order')
                ->get()
                ->reject(fn (ProjectTask $sibling) => $sibling->is($task))
                ->values();

            $destinationSiblings = $sourceColumnId === $destination->id
                ? $sourceSiblings
                : ProjectTask::query()
                    ->where('project_column_id', $destination->id)
                    ->lockForUpdate()
                    ->orderBy('order')
                    ->get()
                    ->values();

            $targetIndex = max(0, min($targetIndex, $destinationSiblings->count()));
            $destinationSiblings->splice($targetIndex, 0, [$task]);

            foreach ($destinationSiblings as $index => $sibling) {
                $sibling->is($task)
                    ? $task->update(['project_column_id' => $destination->id, 'order' => $index])
                    : $sibling->update(['order' => $index]);
            }

            if ($sourceColumnId !== $destination->id) {
                foreach ($sourceSiblings as $index => $sibling) {
                    $sibling->update(['order' => $index]);
                }
            }

            return $task->fresh();
        });
    }
}
