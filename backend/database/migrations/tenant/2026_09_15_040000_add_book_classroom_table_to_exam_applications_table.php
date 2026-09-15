<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. Replaces the admin form's free-text `room_number` (added in
 * 2026_09_15_030000, never deployed) with real `classroom_id`/`table_id`
 * dropdowns — see the redesigned Application Form. `table_no` is untouched
 * and stays exactly as before: it's still what the student self-service
 * flow writes (StoreMyExamApplicationRequest — a free-text field there,
 * never a room/table picker), so both flows keep working side by side.
 *
 * `book_id` is which specific Book (within the enrollment's course package)
 * this application examines — a package can bundle several; see
 * CoursePackage::books(). Nullable: nothing forces picking one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_applications', function (Blueprint $table) {
            $table->dropColumn('room_number');

            $table->foreignId('book_id')->nullable()->after('enrollment_id')->constrained('books')->nullOnDelete();
            $table->foreignId('classroom_id')->nullable()->after('table_no')->constrained('classrooms')->nullOnDelete();
            $table->foreignId('table_id')->nullable()->after('classroom_id')->constrained('classroom_tables')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('exam_applications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('book_id');
            $table->dropConstrainedForeignId('classroom_id');
            $table->dropConstrainedForeignId('table_id');

            $table->string('room_number', 20)->nullable();
        });
    }
};
