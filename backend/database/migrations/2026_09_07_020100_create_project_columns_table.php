<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One lane on a project's Kanban board — fully admin-defined per project
 * (not a fixed To-Do/In-Progress/Done set), ordered by `order` and
 * reorderable by dragging (see ProjectColumnController::reorder()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_columns', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();

            $table->string('name');
            $table->string('color', 20)->nullable();
            $table->unsignedInteger('order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'project_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_columns');
    }
};
