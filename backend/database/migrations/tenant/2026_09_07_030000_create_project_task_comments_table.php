<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A comment left on a Kanban card — shown alongside its move history (see
 * ProjectTask::auditActionForDirty()) in the "Edit card" modal. Anyone who
 * can update the task's project can comment; see ProjectTaskCommentPolicy.
 *
 * Lives in the school's own database, same as its parent ProjectTask — no
 * `tenant_id` column. `user_id` stays a plain bigint with no DB-level
 * foreign key, same reasoning as Project::created_by.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_task_comments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('project_task_id')->constrained('project_tasks')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');

            $table->text('body');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['project_task_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_task_comments');
    }
};
