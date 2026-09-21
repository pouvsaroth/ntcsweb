<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "This card depends on that card" — a directed edge between two tasks.
 * `project_task_id` is the dependent card, `depends_on_project_task_id` is
 * the blocker. The primary key doubles as the uniqueness guard against
 * duplicate edges; self-dependency and cross-project edges are rejected at
 * the application layer (see StoreProjectTaskDependencyRequest).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_task_dependencies', function (Blueprint $table) {
            $table->foreignId('project_task_id')->constrained('project_tasks')->cascadeOnDelete();
            $table->foreignId('depends_on_project_task_id')->constrained('project_tasks')->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['project_task_id', 'depends_on_project_task_id']);
            $table->index('depends_on_project_task_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_task_dependencies');
    }
};
