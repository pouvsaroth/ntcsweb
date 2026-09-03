<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retired: Staff (with a "Teacher" position — see TeacherPositionSeeder) is
 * now the single record of who teaches, replacing this standalone profile
 * table. Nothing references `teachers` anymore as of the previous
 * migration, which retargeted `classes.teacher_id` to `staff.id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('teachers');
    }

    public function down(): void
    {
        Schema::create('teachers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('employee_code', 32);
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('specialization')->nullable();
            $table->text('bio')->nullable();
            $table->date('hire_date')->nullable();
            $table->string('status', 20)->default('active');

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'employee_code']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'user_id']);
        });
    }
};
