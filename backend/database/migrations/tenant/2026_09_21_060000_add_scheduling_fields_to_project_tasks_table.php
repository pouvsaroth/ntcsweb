<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Start Date, Estimated Hours, and an optional Sprint/Milestone — see ProjectMilestone's migration. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_tasks', function (Blueprint $table) {
            $table->date('start_date')->nullable()->after('description');
            $table->decimal('estimated_hours', 6, 2)->nullable()->after('due_date');
            $table->foreignId('project_milestone_id')->nullable()->after('project_column_id')
                ->constrained('project_milestones')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('project_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_milestone_id');
            $table->dropColumn(['start_date', 'estimated_hours']);
        });
    }
};
