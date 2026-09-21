<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The many-to-many between a Kanban card and the labels attached to it. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_task_label', function (Blueprint $table) {
            $table->foreignId('project_task_id')->constrained('project_tasks')->cascadeOnDelete();
            $table->foreignId('project_label_id')->constrained('project_labels')->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['project_task_id', 'project_label_id']);
            $table->index('project_label_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_task_label');
    }
};
