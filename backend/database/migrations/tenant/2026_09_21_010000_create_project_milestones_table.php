<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Sprint/Milestone on a project's Kanban board — scoped to exactly one
 * project, same shape and lifecycle as ProjectColumn (admin-defined, ordered,
 * soft-deletable). A card's `project_milestone_id` (see the migration adding
 * it to `project_tasks`) is independent of its column/status: moving a card
 * between columns never touches its milestone.
 *
 * Lives in the school's own database, same as Project/ProjectColumn — no
 * `tenant_id` column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_milestones', function (Blueprint $table) {
            $table->id();

            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();

            $table->string('name');
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();
            $table->unsignedInteger('order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['project_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_milestones');
    }
};
