<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One card on a project's Kanban board. `order` positions it within its
 * column (see ProjectColumn); moving a card between/within columns
 * (ProjectTaskService::move()) reindexes `order` for every affected column
 * so drag-and-drop never leaves gaps or duplicate positions.
 *
 * Lives in the school's own database, same as Project/ProjectColumn — no
 * `tenant_id` column. `assignee_id`/`created_by` stay plain bigints with no
 * DB-level foreign key, same reasoning as Project::created_by.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_tasks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('project_column_id')->constrained('project_columns')->cascadeOnDelete();

            $table->string('title');
            $table->text('description')->nullable();
            $table->string('priority', 10)->default('medium'); // low | medium | high
            $table->date('due_date')->nullable();
            $table->unsignedInteger('order')->default(0);

            $table->unsignedBigInteger('assignee_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['project_column_id', 'order']);
            $table->index('assignee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_tasks');
    }
};
