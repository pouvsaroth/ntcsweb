<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** One checklist/subtask line on a Kanban card — ordered, independently completable. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_task_checklist_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('project_task_id')->constrained('project_tasks')->cascadeOnDelete();

            $table->string('title');
            $table->boolean('is_completed')->default(false);
            $table->unsignedInteger('order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['project_task_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_task_checklist_items');
    }
};
