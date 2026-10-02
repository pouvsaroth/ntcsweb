<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only log of every class/table/course change on an enrollment (see
 * EnrollmentService::transferClass() and EnrollmentController::changeTable()).
 * Those changes now update the enrollment row in place rather than dropping
 * it and opening a new one, so this table is where the "what was it before"
 * lives. Never updated or deleted once written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollment_transfer_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_class_id')->nullable()->constrained('classes')->nullOnDelete();
            $table->foreignId('to_class_id')->nullable()->constrained('classes')->nullOnDelete();
            $table->foreignId('from_table_id')->nullable()->constrained('classroom_tables')->nullOnDelete();
            $table->foreignId('to_table_id')->nullable()->constrained('classroom_tables')->nullOnDelete();
            $table->foreignId('from_course_package_id')->nullable()->constrained('course_packages')->nullOnDelete();
            $table->foreignId('to_course_package_id')->nullable()->constrained('course_packages')->nullOnDelete();
            // No DB-level foreign key — same reason as enrollment_status_histories.
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->timestamps();

            $table->index('enrollment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollment_transfer_histories');
    }
};
