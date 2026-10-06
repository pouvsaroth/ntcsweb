<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HRM > Organization Management's own lists: branches, teams, job grades
 * and job levels — same shape as `departments` (code/name/description/
 * is_active), which that page shows as another tab. Branches also keep a
 * phone and address. Nothing references these yet; linking staff (and
 * nesting Branch > Department > Team) comes in a follow-up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();

            $table->string('code', 20);
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('phone', 50)->nullable();
            $table->string('address', 500)->nullable();

            $table->timestamps();

            $table->unique('code');
            $table->index('is_active');
        });

        Schema::create('teams', function (Blueprint $table) {
            $table->id();

            $table->string('code', 20);
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique('code');
            $table->index('is_active');
        });

        Schema::create('job_grades', function (Blueprint $table) {
            $table->id();

            $table->string('code', 20);
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique('code');
            $table->index('is_active');
        });

        Schema::create('job_levels', function (Blueprint $table) {
            $table->id();

            $table->string('code', 20);
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique('code');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_levels');
        Schema::dropIfExists('job_grades');
        Schema::dropIfExists('teams');
        Schema::dropIfExists('branches');
    }
};
