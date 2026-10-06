<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HRM > Leave Management's set-up:
 *
 * - leave_types: the kinds of leave staff can take (Annual, Sick,
 *   Maternity, ...) — paid or not, whether half days are allowed, whether a
 *   supporting file is required, and optionally only for one gender.
 * - leave_policies: how many days of a type a staff member gets each year,
 *   for everyone or one job grade (the grade's own policy wins), with
 *   eligibility after so many months of service, proration in the year they
 *   join, extra days for long service, and how much may carry forward into
 *   the next year.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->string('color', 7)->nullable();
            $table->boolean('is_paid')->default(true);
            $table->boolean('allow_half_day')->default(true);
            $table->boolean('requires_attachment')->default(false);
            $table->string('gender', 10)->nullable(); // null = everyone | male | female
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('leave_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_type_id')->constrained('leave_types')->cascadeOnDelete();
            $table->foreignId('job_grade_id')->nullable()->constrained('job_grades')->nullOnDelete();
            $table->string('name');
            $table->decimal('days_per_year', 5, 1);
            $table->unsignedSmallInteger('min_service_months')->default(0);
            $table->boolean('prorate_first_year')->default(true);
            $table->unsignedTinyInteger('service_bonus_every_years')->nullable();
            $table->decimal('service_bonus_days', 4, 1)->default(0);
            $table->decimal('max_days_per_year', 5, 1)->nullable();
            $table->decimal('max_carry_forward_days', 5, 1)->default(0);
            $table->unsignedTinyInteger('carry_forward_expiry_months')->nullable();
            $table->unsignedSmallInteger('max_consecutive_days')->nullable();
            $table->unsignedSmallInteger('min_notice_days')->default(0);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['leave_type_id', 'job_grade_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_policies');
        Schema::dropIfExists('leave_types');
    }
};
