<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A comment left on a Kanban card — shown alongside its move history (see
 * ProjectTask::auditActionForDirty()) in the "Edit card" modal. Anyone who
 * can update the task's project can comment; see ProjectTaskCommentPolicy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_task_comments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('project_task_id')->constrained('project_tasks')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->text('body');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'project_task_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_task_comments');
    }
};
