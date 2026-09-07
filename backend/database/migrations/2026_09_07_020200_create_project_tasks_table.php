<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One card on a project's Kanban board. `order` positions it within its
 * column (see ProjectColumn); moving a card between/within columns
 * (ProjectTaskService::move()) reindexes `order` for every affected column
 * so drag-and-drop never leaves gaps or duplicate positions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_tasks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('project_column_id')->constrained('project_columns')->cascadeOnDelete();

            $table->string('title');
            $table->text('description')->nullable();
            $table->string('priority', 10)->default('medium'); // low | medium | high
            $table->date('due_date')->nullable();
            $table->unsignedInteger('order')->default(0);

            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'project_column_id', 'order']);
            $table->index(['tenant_id', 'assignee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_tasks');
    }
};
