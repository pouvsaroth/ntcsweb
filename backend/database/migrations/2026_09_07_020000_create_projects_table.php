<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Kanban-style project — see ProjectColumn (its board's lanes, ordered and
 * fully admin-defined, not a fixed To-Do/In-Progress/Done set) and
 * ProjectTask (the cards within a column). Any staff/admin can create and
 * use projects — see ProjectPolicy's docblock — there is no per-project
 * membership gate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active'); // active | archived

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
